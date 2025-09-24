<?php

namespace PP\PHPX\Exceptions;

use RuntimeException;

class ComponentValidationException extends RuntimeException
{
    private string $propName;
    private string $componentName;
    private array $availableProps;

    public function __construct(
        string $propName,
        string $componentName,
        array $availableProps,
        string $context = ''
    ) {
        $this->propName = $propName;
        $this->componentName = $componentName;
        $this->availableProps = $availableProps;

        $availableList = implode(', ', $availableProps);

        $message = "Invalid prop '{$propName}' for component '{$componentName}'.\n";
        $message .= "Available props: {$availableList}";

        if ($context) {
            $message .= "\n{$context}";
        }

        parent::__construct($message);
    }

    public function getPropName(): string
    {
        return $this->propName;
    }

    public function getComponentName(): string
    {
        return $this->componentName;
    }

    public function getAvailableProps(): array
    {
        return $this->availableProps;
    }
}
