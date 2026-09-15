<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Http;

use WorkerSafety\Exception\ReplayException;

/** Validates the complete wire framing before exposing any body to assertions. */
final class ChunkedBodyDecoder
{
    public function decode(string $url, string $wire): string
    {
        $offset = 0;
        $body = '';
        $length = strlen($wire);

        while (true) {
            $line = $this->line($url, $wire, $offset);
            // Extensions are metadata; neither they nor trailers are body data.
            $sizeText = explode(';', $line, 2)[0];
            if (preg_match('/^[0-9a-fA-F]+$/D', $sizeText) !== 1) {
                throw ReplayException::malformedResponse($url, 'invalid chunk size');
            }
            $size = hexdec($sizeText);
            if ($size > $length - $offset) {
                throw ReplayException::malformedResponse($url, 'incomplete chunk data');
            }
            $size = (int) $size;
            if ($size === 0) {
                while (($trailer = $this->line($url, $wire, $offset)) !== '') {
                    if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+:[^\r\n]*$/D', $trailer) !== 1) {
                        throw ReplayException::malformedResponse($url, 'invalid chunk trailer');
                    }
                }
                if ($offset !== $length) {
                    throw ReplayException::malformedResponse($url, 'unexpected bytes after final chunk');
                }

                return $body;
            }
            $body .= substr($wire, $offset, $size);
            $offset += $size;
            if (substr($wire, $offset, 2) !== "\r\n") {
                throw ReplayException::malformedResponse($url, 'missing chunk delimiter');
            }
            $offset += 2;
        }
    }

    private function line(string $url, string $wire, int &$offset): string
    {
        $end = strpos($wire, "\r\n", $offset);
        if ($end === false) {
            throw ReplayException::malformedResponse($url, 'incomplete chunk framing');
        }
        $line = substr($wire, $offset, $end - $offset);
        $offset = $end + 2;

        return $line;
    }
}
