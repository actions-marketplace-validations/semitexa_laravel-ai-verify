<?php

declare(strict_types=1);

namespace Semitexa\LaravelAiVerify\Receipts;

use Semitexa\LaravelAiVerify\Support\ProcessRunner;

/**
 * Does a commit do what its subject says? A receipt proves the checks passed,
 * not that the change is the one the message claims. An agent once committed
 * "Limit contact name to 250 chars" with a diff that limited the email
 * instead: the tests passed, the trailer was earned, the claim was false.
 *
 * Two deterministic signals, both advisory:
 *
 *  - term:   the subject names something the changed files contain
 *            (`name` in StoreContactMessageRequest.php), yet no changed line
 *            touches it;
 *  - number: the subject gives a number (250) that no added line contains.
 *
 * Words the changed files never mention are ignored, so prose like "tidy" or
 * "faster" cannot trip it.
 */
final class CommitClaims
{
    /** Broad commits name broad things: past this many files the signal is noise. */
    private const MAX_FILES = 25;

    private const MAX_BYTES = 262144;

    /** A named thing counts only this close to a changed line: the wrong-line slip happens next door. */
    private const NEAR = 12;

    /** How alike two lines' shapes must be to count as twins. */
    private const TWIN = 0.8;

    /**
     * Where a named thing can be missed: application code. Prose, data files,
     * templates and tests are full of look-alike lines and only add noise.
     */
    private const CODE = '/\\.(php|js|mjs|cjs|ts|tsx|jsx|vue|svelte|py|rb|go|java|kt|cs|rs|swift|sql)$/i';

    private const TESTS = '#(^|/)(tests?|spec|__tests__)/|\\.(test|spec)\\.[a-z]+$|Test\\.php$#i';

    /** Commit-message vocabulary that describes the change rather than naming code. */
    private const STOPWORDS = [
        'a', 'about', 'add', 'added', 'adding', 'adds', 'after', 'again', 'agent', 'all', 'allow', 'allows', 'also', 'an', 'and', 'any', 'are', 'around', 'as', 'at',
        'back', 'be', 'cap', 'catch', 'before', 'being', 'better', 'between', 'both', 'broken', 'bug', 'bump', 'but', 'by', 'can', 'cannot', 'case', 'cases', 'change', 'changed', 'changes',
        'char', 'chars', 'character', 'characters', 'check', 'checks', 'clean', 'cleanup', 'code', 'correct', 'correctly', 'could', 'default', 'delete', 'deleted', 'do',
        'document', 'documents', 'does', 'doc', 'docs', 'drop', 'during', 'each', 'edge', 'else', 'ensure', 'every', 'extract', 'few', 'file', 'files', 'first', 'fix', 'fixed', 'fixes', 'fixing',
        'follow', 'for', 'guard', 'from', 'get', 'gets', 'handle', 'handles', 'has', 'have', 'how', 'if', 'ignore', 'ignores', 'improve', 'in', 'initial', 'into', 'is', 'it', 'its', 'just', 'keep',
        'last', 'less', 'let', 'limit', 'limits', 'line', 'lines', 'long', 'longer', 'make', 'makes', 'many', 'may', 'merge', 'minor', 'missing', 'more', 'most', 'move',
        'moved', 'much', 'must', 'need', 'needs', 'new', 'no', 'not', 'note', 'notes', 'now', 'of', 'off', 'old', 'on', 'once', 'one', 'only', 'onto', 'or', 'other', 'our', 'out', 'over',
        'own', 'per', 'pin', 'prevent', 'proper', 'properly', 'put', 'refactor', 'release', 'remove', 'removed', 'removes', 'rename', 'renamed', 'renames', 'replace', 'restore', 'revert', 'rewrite',
        'run', 'same', 'set', 'sets', 'short', 'shorten', 'should', 'show', 'simplify', 'skip', 'small', 'so', 'some', 'split', 'still', 'stop', 'support', 'than', 'that', 'the',
        'their', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'tidy', 'to', 'too', 'tweak', 'tweaks', 'two', 'typo', 'under', 'up', 'update', 'updated',
        'updates', 'use', 'used', 'uses', 'using', 'via', 'was', 'way', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'why', 'will', 'wip', 'with',
        'without', 'work', 'works', 'would', 'wrong', 'yet', 'you', 'your',
    ];

    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * Claims of what is staged against a commit message file (prepare-commit-msg).
     *
     * @return list<array<string, mixed>>
     */
    public function staged(string $message): array
    {
        $diff = $this->runner->run(['git', 'diff', '--cached', '-U0', '--no-color', '--no-ext-diff', '--no-renames']);

        return $diff->succeeded() ? $this->analyze(self::subject($message), self::parseDiff($diff->stdout), fn (string $path) => $this->blob(':'.$path)) : [];
    }

    /**
     * Claims of one commit against its own subject.
     *
     * @return list<array<string, mixed>>
     */
    public function commit(string $sha, string $subject): array
    {
        $diff = $this->runner->run(['git', 'show', '--format=', '-U0', '--no-color', '--no-ext-diff', '--no-renames', '--first-parent', $sha, '--']);

        return $diff->succeeded() ? $this->analyze(self::subject($subject), self::parseDiff($diff->stdout), fn (string $path) => $this->blob($sha.':'.$path)) : [];
    }

    /**
     * @param  array<string, array{added: array<int, string>, removed: array<int, string>, at: int}>  $files  path => changed lines
     * @param  \Closure(string): ?string  $content  file content after the change
     * @return list<array<string, mixed>>
     */
    public function analyze(string $subject, array $files, \Closure $content): array
    {
        if ($subject === '' || $files === [] || count($files) > self::MAX_FILES || preg_match('/^(merge|revert)\b/i', $subject)) {
            return [];
        }

        $changed = [];
        $added = [];

        foreach ($files as $path => $lines) {
            foreach (self::words($path) as $w) {
                $changed[$w] = true;
            }

            foreach ([...$lines['added'], ...$lines['removed']] as $text) {
                foreach (self::words($text) as $w) {
                    $changed[$w] = true;
                }
            }

            foreach ($lines['added'] as $text) {
                foreach (self::words($text) as $w) {
                    $added[$w] = true;
                }
            }
        }

        $findings = [];
        $texts = [];

        foreach (self::terms($subject) as $term) {
            if (self::known($term, $changed)) {
                continue;
            }

            foreach ($files as $path => $lines) {
                if (! preg_match(self::CODE, $path) || preg_match(self::TESTS, $path)) {
                    continue;
                }

                $texts[$path] ??= $content($path);
                $pair = $texts[$path] !== null ? self::sibling($term, $texts[$path], $lines) : null;

                if ($pair !== null) {
                    $findings[] = self::finding('term', $term, $path, $pair[0], $files, $pair[1]);
                    break;
                }
            }
        }

        if ($added !== []) {
            foreach (self::numbers($subject) as $number) {
                if (! isset($added[$number])) {
                    $findings[] = self::finding('number', $number, null, null, $files);
                }
            }
        }

        return $findings;
    }

    /**
     * Changed lines per file from a `-U0` unified diff, with new-side line numbers.
     *
     * @return array<string, array{added: array<int, string>, removed: array<int, string>, at: int}>
     */
    public static function parseDiff(string $diff): array
    {
        $files = [];
        $path = null;
        $old = '';
        $new = 0;
        $binary = false;

        foreach (explode("\n", $diff) as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $path = null;
                $binary = false;
            } elseif (str_starts_with($line, 'Binary files ')) {
                $binary = true;
            } elseif (str_starts_with($line, '--- ')) {
                $old = substr($line, 4) === '/dev/null' ? '' : (string) preg_replace('#^a/#', '', substr($line, 4));
            } elseif (str_starts_with($line, '+++ ')) {
                $target = substr($line, 4);
                $path = $target === '/dev/null' ? $old : (string) preg_replace('#^b/#', '', $target);
                $files[$path] ??= ['added' => [], 'removed' => [], 'at' => 0];
            } elseif ($path !== null && ! $binary && preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $line, $m)) {
                $new = (int) $m[1];
                $files[$path]['at'] = $files[$path]['at'] ?: max(1, $new);
            } elseif ($path !== null && ! $binary && str_starts_with($line, '+')) {
                $files[$path]['added'][$new++] = substr($line, 1);
            } elseif ($path !== null && ! $binary && str_starts_with($line, '-')) {
                $files[$path]['removed'][max(1, $new)] = substr($line, 1);
            }
        }

        return $files;
    }

    /** First non-comment line of a commit message, without a Conventional Commits prefix. */
    public static function subject(string $message): string
    {
        foreach (explode("\n", $message) as $line) {
            $line = trim($line);

            if ($line !== '' && ! str_starts_with($line, '#')) {
                return trim((string) preg_replace('/^[a-z]+(\([^)]*\))?!?:\s*/i', '', $line));
            }
        }

        return '';
    }

    /**
     * Lower-case word parts of code or prose: camelCase, snake_case, kebab-case
     * and dotted names all split, so `contactName` and `contact.name` both yield
     * contact + name.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[A-Z]+(?![a-z])|[A-Za-z][a-z0-9]*|\d+/', (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text), $m);

        return array_map('strtolower', $m[0]);
    }

    /** @return list<string> subject words that could name code */
    private static function terms(string $subject): array
    {
        $words = array_filter(self::words($subject), static fn (string $w) => strlen($w) >= 3 && ! ctype_digit($w) && ! in_array($w, self::STOPWORDS, true));

        return array_values(array_unique($words));
    }

    /** @return list<string> numbers the subject states, ignoring versions, issue refs and sizes like 2x */
    private static function numbers(string $subject): array
    {
        $plain = (string) preg_replace('/v?\d+(?:\.\d+)+|#\d+|\b[0-9a-f]{7,40}\b/i', ' ', $subject);
        preg_match_all('/(?<![\w.])(\d{2,})(?![\w.]*\d)/', $plain, $m);

        return array_values(array_unique($m[1]));
    }

    /** @param array<string, true> $vocabulary */
    private static function known(string $term, array $vocabulary): bool
    {
        foreach (self::forms($term) as $form) {
            if (isset($vocabulary[$form])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the word with and without a plural ending */
    private static function forms(string $term): array
    {
        $forms = [$term, $term.'s', $term.'es'];

        if (str_ends_with($term, 'ies')) {
            $forms[] = substr($term, 0, -3).'y';
        } elseif (str_ends_with($term, 'es')) {
            $forms[] = substr($term, 0, -2);
        }

        if (str_ends_with($term, 's')) {
            $forms[] = substr($term, 0, -1);
        }

        return $forms;
    }

    /**
     * The wrong-line slip: a line next to the change names the term and has
     * the same shape as a changed line that does not. `'name' => [...]` left
     * alone while its twin `'email' => [...]` changed is exactly that.
     *
     * @param  array{added: array<int, string>, removed: array<int, string>, at: int}  $lines
     * @return array{0: int, 1: int}|null [line naming the term, changed line]
     */
    private static function sibling(string $term, string $content, array $lines): ?array
    {
        if (strlen($content) > self::MAX_BYTES || str_contains(substr($content, 0, 8000), "\0")) {
            return null;
        }

        $changed = $lines['added'] !== [] ? $lines['added'] : $lines['removed'];
        $forms = array_flip(self::forms($term));
        $source = explode("\n", $content);
        $best = null;

        foreach ($changed as $at => $text) {
            $shape = self::shape($text);

            if (strlen($shape) < 4) {
                continue;
            }

            for ($n = max(1, $at - self::NEAR); $n <= min(count($source), $at + self::NEAR); $n++) {
                $line = $source[$n - 1];

                if (isset($lines['added'][$n]) || array_intersect_key($forms, array_flip(self::words($line))) === []) {
                    continue;
                }

                $other = self::shape($line);
                $similarity = 1 - levenshtein($shape, $other) / max(strlen($shape), strlen($other));

                if ($similarity >= self::TWIN && ($best === null || $similarity > $best[2] || ($similarity === $best[2] && abs($n - $at) < abs($best[0] - $best[1])))) {
                    $best = [$n, $at, $similarity];
                }
            }
        }

        return $best !== null ? [$best[0], $best[1]] : null;
    }

    /** A line as a string of token kinds: names, strings and numbers collapse, punctuation stays. */
    private static function shape(string $line): string
    {
        $line = (string) preg_replace([
            '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/',
            '/\$?[A-Za-z_][A-Za-z0-9_]*/',
            '/\d+(?:\.\d+)?/',
            '/\s+/',
        ], ["\x01", "\x02", "\x03", ''], $line); // kinds as control bytes, so a later pattern cannot re-match them

        return substr($line, 0, 200);
    }

    /**
     * @param  array<string, array{added: array<int, string>, removed: array<int, string>, at: int}>  $files
     * @return array<string, mixed>
     */
    private static function finding(string $kind, string $term, ?string $path, ?int $line, array $files, ?int $twin = null): array
    {
        // Point at the change itself: in the file the term lives in, else the first changed file.
        $at = $path !== null && isset($files[$path]) ? $path : (string) array_key_first($files);
        $lines = $files[$at];
        $changedLine = $twin ?? ($lines['added'] !== [] ? (int) array_key_first($lines['added']) : $lines['at']);
        $changedText = trim((string) ($lines['added'][$changedLine] ?? $lines['removed'][$changedLine] ?? reset($lines['removed']) ?: ''));
        $excerpt = mb_strlen($changedText) > 80 ? mb_substr($changedText, 0, 77).'…' : $changedText;

        $message = $kind === 'term'
            ? sprintf('The subject names `%s`, but its line %s:%d is unchanged; the change went to the similar line %d', $term, $path, $line, $changedLine)
            : sprintf('The subject says %s, but no added line contains it; the change is at %s:%d', $term, $at, $changedLine);
        $message .= $excerpt !== '' ? sprintf(': `%s`', $excerpt) : '.';

        return [
            'kind' => $kind,
            'term' => $term,
            'path' => $path,
            'line' => $line,
            'changed' => ['path' => $at, 'line' => $changedLine, 'text' => $changedText],
            'message' => $message,
        ];
    }

    private function blob(string $spec): ?string
    {
        $out = $this->runner->run(['git', 'show', $spec]);

        return $out->succeeded() ? $out->stdout : null;
    }
}
