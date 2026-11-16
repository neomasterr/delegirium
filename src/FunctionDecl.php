<?php

declare(strict_types=1);

namespace Delegirium;

/**
 * Объявление функции в исходнике Delegirium.
 *
 * Тело «настоящей» функции содержит токены, а у AI-функции вместо тела стоит
 * ключевое слово `ai;`. Такой файл остаётся почти валидным PHP: единственное
 * синтаксическое отличие — этот маркер, который компилятор разворачивает
 * в сгенерированные операторы.
 */
final class FunctionDecl
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $name,
        public readonly string $signature,
        public readonly string $returnType,
        public readonly string $doc,
        public readonly array $tags,
        public readonly ?string $className,
        public readonly string $namespace,
        public readonly string $indent,
        public readonly int $startOffset,
        public readonly int $endOffset,
        public readonly int $line,
    ) {
    }

    public function isPure(): bool
    {
        return in_array('pure', $this->tags, true);
    }

    public function fqName(): string
    {
        $prefix = $this->namespace !== '' ? $this->namespace . '\\' : '';

        if ($this->className !== null) {
            return $prefix . $this->className . '::' . $this->name;
        }

        return $prefix . $this->name;
    }

    public function request(?string $feedback = null): GenerationRequest
    {
        return new GenerationRequest(
            name: $this->name,
            fqName: $this->fqName(),
            signature: $this->signature,
            returnType: $this->returnType,
            doc: $this->doc,
            className: $this->className,
            namespace: $this->namespace,
            pure: $this->isPure(),
            feedback: $feedback,
        );
    }
}
