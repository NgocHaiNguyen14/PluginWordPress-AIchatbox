<?php

class AICB_Document_Parser
{
    public static function extract_text(string $file_path, string $file_type): string
    {
        switch ($file_type) {
            case 'txt':
                return self::extract_txt($file_path);
            case 'pdf':
                return self::extract_pdf($file_path);
            default:
                throw new InvalidArgumentException("Unsupported file type: {$file_type}");
        }
    }

    private static function extract_txt(string $file_path): string
    {
        $contents = file_get_contents($file_path);
        return false === $contents ? '' : $contents;
    }

    private static function extract_pdf(string $file_path): string
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($file_path);
        return $pdf->getText();
    }
}
