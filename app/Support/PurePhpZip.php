<?php

namespace App\Support;

/**
 * Minimal ZipArchive-compatible reader for xlsx when ext-zip is missing.
 */
class PurePhpZip
{
    public const FL_NOCASE = 1;
    public const FL_NODIR = 2;
    public const ER_OK = 0;
    public const ER_NOZIP = 19;

    public int $numFiles = 0;

    public string $filename = '';

    /** @var array<int, array{name: string, method: int, csize: int, size: int, localOff: int}> */
    private array $entries = [];

    private string $binary = '';

    public function open(string $filename, int $flags = 0): bool|int
    {
        $this->close();

        if (!is_readable($filename)) {
            return false;
        }

        $data = file_get_contents($filename);
        if ($data === false || strlen($data) < 22) {
            return false;
        }

        $len = strlen($data);
        $eocd = false;
        $maxScan = min($len - 22, 65535);
        for ($i = 0; $i <= $maxScan; $i++) {
            $pos = $len - 22 - $i;
            if (substr($data, $pos, 4) === "PK\x05\x06") {
                $eocd = $pos;
                break;
            }
        }

        if ($eocd === false) {
            return false;
        }

        $totalEntries = unpack('v', substr($data, $eocd + 10, 2))[1];
        $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];

        $offset = $cdOffset;
        $entries = [];
        for ($n = 0; $n < $totalEntries; $n++) {
            if (substr($data, $offset, 4) !== "PK\x01\x02") {
                break;
            }

            $method = unpack('v', substr($data, $offset + 10, 2))[1];
            $csize = unpack('V', substr($data, $offset + 20, 4))[1];
            $size = unpack('V', substr($data, $offset + 24, 4))[1];
            $fnLen = unpack('v', substr($data, $offset + 28, 2))[1];
            $exLen = unpack('v', substr($data, $offset + 30, 2))[1];
            $cmLen = unpack('v', substr($data, $offset + 32, 2))[1];
            $localOff = unpack('V', substr($data, $offset + 42, 4))[1];
            $name = substr($data, $offset + 46, $fnLen);

            $entries[] = [
                'name' => str_replace('\\', '/', $name),
                'method' => $method,
                'csize' => $csize,
                'size' => $size,
                'localOff' => $localOff,
            ];

            $offset += 46 + $fnLen + $exLen + $cmLen;
        }

        $this->binary = $data;
        $this->entries = $entries;
        $this->numFiles = count($entries);
        $this->filename = $filename;

        return true;
    }

    public function close(): bool
    {
        $this->entries = [];
        $this->binary = '';
        $this->numFiles = 0;
        $this->filename = '';

        return true;
    }

    public function locateName(string $name, int $flags = 0): int|false
    {
        $needle = $this->normalizeName($name, $flags);
        foreach ($this->entries as $index => $entry) {
            $haystack = $this->normalizeName($entry['name'], $flags);
            if ($haystack === $needle) {
                return $index;
            }
        }

        return false;
    }

    public function getFromName(string $name, int $len = 0, int $flags = 0): string|false
    {
        $index = $this->locateName($name, $flags);
        if ($index === false) {
            return false;
        }

        return $this->getFromIndex($index, $len, $flags);
    }

    public function getFromIndex(int $index, int $len = 0, int $flags = 0): string|false
    {
        if (!isset($this->entries[$index])) {
            return false;
        }

        $contents = $this->extract($this->entries[$index]);
        if ($contents === false) {
            return false;
        }

        if ($len > 0) {
            return substr($contents, 0, $len);
        }

        return $contents;
    }

    public function statName(string $name, int $flags = 0): array|false
    {
        $index = $this->locateName($name, $flags);
        if ($index === false) {
            return false;
        }

        $entry = $this->entries[$index];

        return [
            'name' => $entry['name'],
            'index' => $index,
            'crc' => 0,
            'size' => $entry['size'],
            'comp_size' => $entry['csize'],
            'comp_method' => $entry['method'],
        ];
    }

    public function getNameIndex(int $index, int $flags = 0): string|false
    {
        return $this->entries[$index]['name'] ?? false;
    }

    /**
     * @param  array{name: string, method: int, csize: int, size: int, localOff: int}  $entry
     */
    private function extract(array $entry): string|false
    {
        $local = $entry['localOff'];
        if (substr($this->binary, $local, 4) !== "PK\x03\x04") {
            return false;
        }

        $fnLen = unpack('v', substr($this->binary, $local + 26, 2))[1];
        $exLen = unpack('v', substr($this->binary, $local + 28, 2))[1];
        $dataStart = $local + 30 + $fnLen + $exLen;
        $compressed = substr($this->binary, $dataStart, $entry['csize']);

        if ($entry['method'] === 0) {
            return $compressed;
        }

        if ($entry['method'] === 8) {
            $raw = @gzinflate($compressed);

            return $raw === false ? false : $raw;
        }

        return false;
    }

    private function normalizeName(string $name, int $flags): string
    {
        $name = str_replace('\\', '/', ltrim($name, '/'));
        if ($flags & self::FL_NOCASE) {
            $name = strtolower($name);
        }

        return $name;
    }
}
