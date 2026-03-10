<?php
declare(strict_types=1);

/**
 * Mock TCPDF for certificate unit tests.
 * Prevents real PDF output and header() calls. Loaded in bootstrap before plugin.
 */
if (!class_exists('TCPDF')) {
    class TCPDF
    {
        public function __construct($orientation = 'P', $unit = 'mm', $format = 'A4', $unicode = true, $encoding = 'UTF-8', $diskcache = false)
        {
        }

        public function SetCreator(string $creator): void
        {
        }

        public function SetAuthor(string $author): void
        {
        }

        public function SetTitle(string $title): void
        {
        }

        public function SetMargins(float $left, float $top, float $right = -1): void
        {
        }

        public function SetAutoPageBreak(bool $auto, float $margin = 0): void
        {
        }

        public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false): void
        {
        }

        public function SetFont(string $family, string $style = '', $size = null): void
        {
        }

        public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M'): void
        {
        }

        public function Ln($h = null, $cell = false): void
        {
        }

        public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $ln = 1, $x = '', $y = '', $reseth = true, $stretch = 0, $ishtml = false, $autopadding = true, $maxh = 0, $valign = 'T', $fitcell = false): void
        {
        }

        /**
         * @return string Empty string for 'S' (string) mode to avoid output.
         */
        public function Output($name = 'doc.pdf', $dest = 'I', $encoding = '')
        {
            return '';
        }
    }
}
