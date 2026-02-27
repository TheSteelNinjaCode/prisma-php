<?php

declare(strict_types=1);

namespace PP;

final class Rule
{
    /** @var list<string> */
    private array $rules = [];

    private function __construct() {}

    public static function make(): self
    {
        return new self();
    }

    public static function required(): self
    {
        return self::make()->add('required');
    }

    public static function optional(): self
    {
        // Convenience alias, no-op in validator but useful semantically
        return self::make();
    }

    public function add(string $rule): self
    {
        $this->rules[] = $rule;
        return $this;
    }

    public function min(int $value): self
    {
        return $this->add("min:$value");
    }

    public function max(int $value): self
    {
        return $this->add("max:$value");
    }

    public function size(int $value): self
    {
        return $this->add("size:$value");
    }

    public function between(int $min, int $max): self
    {
        return $this->add("between:$min,$max");
    }

    public function startsWith(string $value): self
    {
        return $this->add("startsWith:$value");
    }

    public function endsWith(string $value): self
    {
        return $this->add("endsWith:$value");
    }

    public function confirmed(): self
    {
        return $this->add("confirmed");
    }

    public function email(): self
    {
        return $this->add("email");
    }

    public function url(): self
    {
        return $this->add("url");
    }

    public function ip(): self
    {
        return $this->add("ip");
    }

    public function uuid(): self
    {
        return $this->add("uuid");
    }

    public function ulid(): self
    {
        return $this->add("ulid");
    }

    public function cuid(): self
    {
        return $this->add("cuid");
    }

    public function int(): self
    {
        return $this->add("int");
    }

    public function float(): self
    {
        return $this->add("float");
    }

    public function boolean(): self
    {
        return $this->add("boolean");
    }

    /**
     * @param list<string|int|float|bool> $values
     */
    public function in(array $values): self
    {
        return $this->add('in:' . implode(',', array_map(static fn($v) => (string)$v, $values)));
    }

    /**
     * @param list<string|int|float|bool> $values
     */
    public function notIn(array $values): self
    {
        return $this->add('notIn:' . implode(',', array_map(static fn($v) => (string)$v, $values)));
    }

    public function date(string $format = 'Y-m-d'): self
    {
        return $this->add("date:$format");
    }

    public function dateFormat(string $format): self
    {
        return $this->add("dateFormat:$format");
    }

    public function before(string $date): self
    {
        return $this->add("before:$date");
    }

    public function after(string $date): self
    {
        return $this->add("after:$date");
    }

    public function json(): self
    {
        return $this->add("json");
    }

    public function timezone(): self
    {
        return $this->add("timezone");
    }

    public function regex(string $pattern): self
    {
        return $this->add("regex:$pattern");
    }

    public function digits(int $count): self
    {
        return $this->add("digits:$count");
    }

    public function digitsBetween(int $min, int $max): self
    {
        return $this->add("digitsBetween:$min,$max");
    }

    /**
     * @param list<string> $extensions
     */
    public function extensions(array $extensions): self
    {
        return $this->add('extensions:' . implode(',', $extensions));
    }

    /**
     * @param list<string> $mimes
     */
    public function mimes(array $mimes): self
    {
        return $this->add('mimes:' . implode(',', $mimes));
    }

    public function file(): self
    {
        return $this->add('file');
    }

    /**
     * Merge another rule builder.
     */
    public function merge(self $other): self
    {
        $this->rules = [...$this->rules, ...$other->rules];
        return $this;
    }

    /**
     * @return list<string>
     */
    public function toArray(): array
    {
        return $this->rules;
    }

    public function toString(): string
    {
        return implode('|', $this->rules);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
