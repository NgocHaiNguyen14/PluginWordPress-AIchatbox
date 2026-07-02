<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-document-parser.php';

final class DocumentParserTest extends TestCase
{
    public function test_extract_text_from_txt_file(): void
    {
        $path = __DIR__ . '/fixtures/sample.txt';

        $text = AICB_Document_Parser::extract_text($path, 'txt');

        $this->assertStringContainsString('Harry is the head of our sales team.', $text);
    }

    public function test_extract_text_from_pdf_file(): void
    {
        $path = __DIR__ . '/fixtures/sample.pdf';

        $text = AICB_Document_Parser::extract_text($path, 'pdf');

        $this->assertStringContainsString('Harry', $text);
    }

    public function test_extract_text_rejects_unsupported_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AICB_Document_Parser::extract_text(__DIR__ . '/fixtures/sample.txt', 'docx');
    }
}
