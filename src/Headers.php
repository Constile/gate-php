<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * An ordered, case-insensitive list of HTTP headers that keeps repeated
 * headers (such as Set-Cookie) as separate entries, like the Fetch API's
 * Headers.
 *
 * @implements \IteratorAggregate<int, array{0: string, 1: string}>
 */
final class Headers implements \IteratorAggregate, \Countable
{
    /** @var list<array{0: string, 1: string}> */
    private array $list = [];

    /**
     * @param iterable<mixed> $headers A map of name => value (or name => list of values), or a list of [name, value] pairs.
     */
    public function __construct(iterable $headers = [])
    {
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_array($value) && count($value) === 2 && isset($value[0], $value[1])) {
                $this->add((string) $value[0], (string) $value[1]);
            } elseif (is_array($value)) {
                foreach ($value as $v) {
                    $this->add((string) $name, (string) $v);
                }
            } else {
                $this->add((string) $name, (string) $value);
            }
        }
    }

    /** The values of a header joined with ", ", as Fetch's Headers.get does; null when it's absent. */
    public function get(string $name): ?string
    {
        $values = $this->getAll($name);
        return $values === [] ? null : implode(', ', $values);
    }

    /** @return list<string> */
    public function getAll(string $name): array
    {
        $out = [];
        foreach ($this->list as [$n, $v]) {
            if (strcasecmp($n, $name) === 0) {
                $out[] = $v;
            }
        }
        return $out;
    }

    public function has(string $name): bool
    {
        foreach ($this->list as [$n]) {
            if (strcasecmp($n, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Replaces every value of a header with one value, keeping the first one's position. */
    public function set(string $name, string $value): void
    {
        self::check($name, $value);
        $out = [];
        $done = false;
        foreach ($this->list as $pair) {
            if (strcasecmp($pair[0], $name) === 0) {
                if (!$done) {
                    $out[] = [$name, $value];
                    $done = true;
                }
                continue;
            }
            $out[] = $pair;
        }
        if (!$done) {
            $out[] = [$name, $value];
        }
        $this->list = $out;
    }

    public function add(string $name, string $value): void
    {
        self::check($name, $value);
        $this->list[] = [$name, $value];
    }

    public function remove(string $name): void
    {
        $this->list = array_values(array_filter($this->list, static fn (array $p): bool => strcasecmp($p[0], $name) !== 0));
    }

    /** @return list<array{0: string, 1: string}> */
    public function all(): array
    {
        return $this->list;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->list);
    }

    public function count(): int
    {
        return count($this->list);
    }

    private static function check(string $name, string $value): void
    {
        if ($name === '' || preg_match('/[^!#$%&\'*+\-.^_`|~0-9A-Za-z]/', $name) === 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the gate never prints exceptions; this one names a header it refuses to send.
            throw new \InvalidArgumentException("Invalid header name: {$name}");
        }
        if (strpbrk($value, "\r\n\0") !== false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the gate never prints exceptions; this one names a header it refuses to send.
            throw new \InvalidArgumentException("Invalid value for header {$name}");
        }
    }
}
