<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The information document of one consent-document version, as PDF BYTES to print and
 * hand to the person (consentDocuments()->getInformationPdf()).
 *
 *   pdf          the PDF bytes, as received. Not frozen (the letterhead follows the
 *                organisation's logo): store your own copy if you need one.
 *   filename     the identity-free filename the server suggested (Content-Disposition),
 *                or null
 *   contentType  "application/pdf"
 */
final class InformationDocument
{
    public function __construct(
        public readonly string $pdf,
        public readonly ?string $filename,
        public readonly string $contentType,
    ) {
    }

    /** The filename of a `Content-Disposition: attachment; filename="..."` header, or null. */
    public static function filenameFrom(?string $contentDisposition): ?string
    {
        if ($contentDisposition === null) {
            return null;
        }
        if (preg_match('/filename="([^"]+)"/', $contentDisposition, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/filename=([^;\s]+)/', $contentDisposition, $m) === 1) {
            return $m[1];
        }
        return null;
    }
}
