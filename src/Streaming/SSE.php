<?php

declare(strict_types=1);

namespace PP\Streaming;

use Generator;

class SSE
{
    public function __construct(
        private Generator $generator,
        private int $statusCode = 200,
        private array $headers = []
    ) {}

    public function send(): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        http_response_code($this->statusCode);

        $defaultHeaders = [
            "Content-Type" => "text/event-stream",
            "Cache-Control" => "no-cache",
            "Connection" => "keep-alive",
            "X-Accel-Buffering" => "no"
        ];

        $headers = array_merge($defaultHeaders, $this->headers);

        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        flush();

        foreach ($this->generator as $item) {
            if ($item instanceof ServerSentEvent) {
                echo $item->encode();
            } else {
                echo (new ServerSentEvent($item))->encode();
            }

            flush();
        }
    }
}
