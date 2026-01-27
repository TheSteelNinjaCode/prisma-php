<?php

declare(strict_types=1);

namespace PP\Streaming;

class ServerSentEvent
{
    public function __construct(
        public mixed $data,
        public ?string $event = null,
        public ?string $id = null,
        public ?int $retry = null
    ) {}

    public function encode(): string
    {
        $buffer = "";

        if ($this->id !== null) {
            $buffer .= "id: {$this->id}\n";
        }

        if ($this->event !== null) {
            $buffer .= "event: {$this->event}\n";
        }

        if ($this->retry !== null) {
            $buffer .= "retry: {$this->retry}\n";
        }

        if (is_array($this->data) || is_bool($this->data)) {
            $dataStr = json_encode($this->data, JSON_UNESCAPED_UNICODE);
        } else {
            $dataStr = (string)$this->data;
        }

        $buffer .= "data: {$dataStr}\n\n";

        return $buffer;
    }
}
