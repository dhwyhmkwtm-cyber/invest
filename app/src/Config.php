<?php
declare(strict_types=1);

namespace Mailer;

final class Config
{
    public function __construct(private array $data)
    {
    }

    /** ドット区切りのキーで取得 (例: 'aws.region') */
    public function get(string $key, mixed $default = null): mixed
    {
        $cur = $this->data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public function with(string $key, mixed $value): self
    {
        $data = $this->data;
        $ref = &$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = $ref[$part] ?? [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
        return new self($data);
    }
}
