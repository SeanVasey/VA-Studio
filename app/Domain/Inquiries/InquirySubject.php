<?php

namespace App\Domain\Inquiries;

/**
 * The closed subject list of the public contact form (BL-50). The chosen label is stored as the inquiry's subject, so
 * staff views, the customer's history and the encrypted five-field payload keep their existing shape. Order inquiries
 * keep their free-text subject (OrderInquiry).
 *
 * "Service request" is how service enquiries reach Sean while the service catalog, deposits and service payments are
 * deferred (DD-03): it creates an ordinary inquiry, books nothing and takes no payment.
 */
final class InquirySubject
{
    public const SERVICE_REQUEST = 'Service request';

    /** Display order on the form; labels only, no product, price or license term. */
    public const PUBLIC_CONTACT = ['General question', 'Licensing question', 'Order or download help', self::SERVICE_REQUEST];

    public static function requirePublicContact(string $subject): void
    {
        if (! in_array($subject, self::PUBLIC_CONTACT, true)) {
            throw new InquiryException(422, ['subject' => ['Choose one of the listed subjects.']]);
        }
    }
}
