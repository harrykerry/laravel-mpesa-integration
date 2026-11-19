<?php

namespace App\Services;

use App\Exceptions\MpesaException;

/**
 * Generates the M-PESA QR codes a customer scans to pay.
 *
 * Unlike the payout and status APIs this one answers immediately, with the
 * QR as a base64 image the frontend can render.
 */
class MpesaQrService
{
    /**
     * Transaction types M-PESA will encode in a QR.
     *
     * BG buy goods, PB paybill, WA send to a business wallet,
     * SM send money to a phone, SB send to a business number.
     */
    public const TRANSACTION_TYPES = ['BG', 'PB', 'WA', 'SM', 'SB'];

    public function __construct(protected readonly MpesaClient $client) {}

    /**
     * Generate a QR code for one payment.
     *
     * @param  string  $reference  What the payment is for, shown to the customer.
     * @param  int  $size  The image's width and height in pixels.
     * @return array<string, string|int|null>
     *
     * @throws MpesaException
     */
    public function generateQrCode(
        float|int $amount,
        string $reference,
        string $transactionType = 'PB',
        ?string $creditPartyIdentifier = null,
        int $size = 300,
    ): array {
        $creditParty = (string) ($creditPartyIdentifier ?? config('mpesa.c2b.shortcode'));

        $response = $this->client->postToMpesa('dynamic_qr', [
            'MerchantName' => (string) config('app.name'),
            'RefNo' => $reference,
            'Amount' => (int) round((float) $amount),
            'TrxCode' => $transactionType,
            'CPI' => $creditParty,
            'Size' => (string) $size,
        ]);

        return [
            'reference' => $reference,
            'amount' => (int) round((float) $amount),
            'transaction_type' => $transactionType,
            'credit_party' => $creditParty,
            'size' => $size,
            'qr_code' => isset($response['QRCode']) ? (string) $response['QRCode'] : null,
            'mpesa_description' => isset($response['ResponseDescription'])
                ? (string) $response['ResponseDescription']
                : 'QR code generated',
        ];
    }
}
