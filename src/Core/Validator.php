<?php

declare(strict_types=1);

namespace SimpleMvc\Core;

/**
 * Validador declarativo mínimo, en el estilo `required|min:2|max:120`.
 *
 * Sustituye al `if (isset($_POST[...]))` inexistente del ejemplo original, donde
 * los datos llegaban al HTML sin validar ni escapar.
 */
final class Validator
{
    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /** @var array<string, string> */
    private array $validated = [];

    private bool $ran = false;

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|array<int, string>>  $rules
     * @param  array<string, string>  $attributes  etiquetas legibles por campo
     */
    public function __construct(
        private array $data,
        private array $rules,
        private array $attributes = [],
        private array $customMessages = []
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|array<int, string>>  $rules
     * @param  array<string, string>  $messages  "campo.regla" => mensaje
     * @param  array<string, string>  $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): self
    {
        return new self($data, $rules, $attributes, $messages);
    }

    public function passes(): bool
    {
        if (!$this->ran) {
            $this->run();
        }

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        $this->passes();

        return $this->errors;
    }

    /**
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        $result = [];

        foreach ($this->errors() as $field => $messages) {
            $result[$field] = $messages[0] ?? '';
        }

        return $result;
    }

    public function firstError(string $field): ?string
    {
        return $this->errors()[$field][0] ?? null;
    }

    public function hasError(string $field): bool
    {
        return isset($this->errors()[$field]);
    }

    /**
     * Solo los campos que pasaron la validación, con sus tipos normalizados.
     *
     * @return array<string, string>
     */
    public function validated(): array
    {
        $this->passes();

        return $this->validated;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function run(): void
    {
        // Se ejecuta una sola vez (ver $ran): no se pisan los errores añadidos
        // a mano con addError() antes de consultar passes().
        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $rules = is_array($rules) ? $rules : array_filter(explode('|', (string) $rules));
            $value = $this->data[$field] ?? null;
            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);
            $nullable = in_array('nullable', $rules, true);

            if ($isEmpty) {
                if ($nullable || !in_array('required', $rules, true)) {
                    continue;
                }

                $this->fail($field, 'required', 'El campo :attribute es obligatorio.');

                continue;
            }

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '' && !$nullable) {
                    $this->fail($field, 'required', 'El campo :attribute es obligatorio.');

                    continue;
                }
            }

            $stringLike = is_string($value) || is_numeric($value);
            $length = $stringLike ? $this->length((string) $value) : 0;

            foreach ($rules as $rule) {
                if ($rule === 'required' || $rule === 'nullable' || $rule === 'filled') {
                    continue;
                }

                [$name, $parameter] = $this->splitRule($rule);
                $this->applyRule($field, $name, $parameter, $value, $length);
            }

            if (!isset($this->errors[$field]) && ($stringLike || is_bool($value))) {
                $this->validated[$field] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }
    }

    private function applyRule(string $field, string $name, ?string $parameter, mixed $value, int $length): void
    {
        $isNumber = is_numeric($value);

        switch ($name) {
            case 'string':
                if (!is_string($value)) {
                    $this->fail($field, 'string', 'El campo :attribute debe ser texto.');
                }

                return;
            case 'array':
                if (!is_array($value)) {
                    $this->fail($field, 'array', 'El campo :attribute debe ser un arreglo.');
                }

                return;
            case 'numeric':
                if (!$isNumber) {
                    $this->fail($field, 'numeric', 'El campo :attribute debe ser un número.');
                }

                return;
            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->fail($field, 'integer', 'El campo :attribute debe ser un número entero.');
                }

                return;
            case 'boolean':
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                    $this->fail($field, 'boolean', 'El campo :attribute debe ser verdadero o falso.');
                }

                return;
            case 'min':
                $min = (float) $parameter;

                if ($isNumber ? $value < $min : $length < $min) {
                    $this->fail($field, 'min', $isNumber
                        ? 'El campo :attribute debe ser mayor o igual que :min.'
                        : 'El campo :attribute debe tener al menos :min caracteres.');
                }

                return;
            case 'max':
                $max = (float) $parameter;

                if ($isNumber ? $value > $max : $length > $max) {
                    $this->fail($field, 'max', $isNumber
                        ? 'El campo :attribute no puede ser mayor que :max.'
                        : 'El campo :attribute no puede superar :max caracteres.');
                }

                return;
            case 'between':
                [$from, $to] = array_map('floatval', explode(',', (string) $parameter));

                if ($length < $from || $length > $to) {
                    $this->fail($field, 'between', 'El campo :attribute debe estar entre :min y :max.');
                }

                return;
            case 'size':
                if ($length !== (int) $parameter) {
                    $this->fail($field, 'size', 'El campo :attribute debe tener :size caracteres.');
                }

                return;
            case 'email':
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->fail($field, 'email', 'El campo :attribute debe ser un correo válido.');
                }

                return;
            case 'url':
                if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                    $this->fail($field, 'url', 'El campo :attribute debe ser una URL válida.');
                }

                return;
            case 'date':
                if (@strtotime((string) $value) === false) {
                    $this->fail($field, 'date', 'El campo :attribute debe ser una fecha válida.');
                }

                return;
            case 'in':
                $options = explode(',', (string) $parameter);

                if (!in_array((string) $value, $options, true)) {
                    $this->fail($field, 'in', 'El valor de :attribute no es válido ('.implode(', ', $options).').');
                }

                return;
            case 'regex':
                if (preg_match((string) $parameter, (string) $value) !== 1) {
                    $this->fail($field, 'regex', 'El formato de :attribute no es válido.');
                }

                return;
            case 'confirmed':
                if (($this->data[$field.'_confirmation'] ?? null) !== $value) {
                    $this->fail($field, 'confirmed', 'La confirmación de :attribute no coincide.');
                }

                return;
            case 'slug':
                if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value) !== 1) {
                    $this->fail($field, 'slug', ':attribute solo puede llevar minúsculas, números y guiones.');
                }

                return;
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitRule(string $rule): array
    {
        [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

        return [strtolower(trim($name)), $parameter === null ? null : trim($parameter)];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function fail(string $field, string $rule, string $template): void
    {
        $label = $this->attributes[$field] ?? $this->humanize($field);
        $message = $this->customMessages[$field.'.'.$rule] ?? $template;

        $this->errors[$field][] = strtr($message, [
            ':attribute' => $label,
            ':min' => (string) ($this->ruleParameter($field, $rule) ?? ''),
            ':max' => (string) ($this->ruleParameter($field, $rule) ?? ''),
            ':size' => (string) ($this->ruleParameter($field, $rule) ?? ''),
        ]);
    }

    private function ruleParameter(string $field, string $rule): ?string
    {
        $rules = $this->rules[$field] ?? [];
        $rules = is_array($rules) ? $rules : explode('|', (string) $rules);

        foreach ($rules as $candidate) {
            [$name, $parameter] = $this->splitRule((string) $candidate);

            if ($name === $rule) {
                return $parameter;
            }
        }

        return null;
    }

    private function humanize(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }
}
