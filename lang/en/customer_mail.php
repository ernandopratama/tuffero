<?php

// Automatic cancellation and refund emails for customers (CustomerOrderUpdate). Never include addresses, card data, or tokens.
return [
    'greeting' => 'Hello,',
    'order' => 'Order number',
    'reference' => 'Request number',
    'status' => 'Status',
    'next' => 'Next step',
    'contact' => 'Questions? Contact Tuffero customer service on WhatsApp :phone.',
    'safety' => 'Tuffero never asks for your OTP code, PIN, or password.',
    'check' => 'Check order status',
    'footer' => 'This is an automatic email from Tuffero. Please do not reply.',

    'messages' => [
        'cancelled_unpaid' => [
            'subject' => 'Order :order has been cancelled',
            'status' => 'Order :order was cancelled before payment.',
            'next' => 'Nothing needs to be paid. If you already paid, contact customer service so the money can be returned.',
        ],
        'cancellation_submitted' => [
            'subject' => 'Cancellation request for order :order received',
            'status' => 'Cancellation request :ref is waiting for review by customer service.',
            'next' => 'Customer service is reviewing your request and we will let you know the result. You do not need to submit it again.',
        ],
        'cancellation_approved' => [
            'subject' => 'Order :order has been cancelled',
            'status' => 'Cancellation request :ref was approved. The order is cancelled.',
            'next' => 'This order was not paid, so there is no money to return.',
        ],
        'cancellation_refund_pending' => [
            'subject' => 'Order :order cancelled, your money will be returned',
            'status' => 'Cancellation request :ref was approved. The order is cancelled and your refund is being processed.',
            'next' => 'We will let you know when the money has been returned. If bank details are needed, customer service will contact you through official Tuffero channels.',
        ],
        'cancellation_rejected' => [
            'subject' => 'Cancellation request for order :order was not approved',
            'status' => 'Cancellation request :ref was not approved. The order is not cancelled.',
            'next' => 'Contact customer service if you have questions about this decision.',
        ],
        'cancellation_refunded' => [
            'subject' => 'Money for order :order has been returned',
            'status' => 'The money for cancellation :ref has been returned.',
            'next' => 'When it arrives depends on your bank or payment method. Contact customer service if you have not received it.',
        ],
        'late_payment_refunded' => [
            'subject' => 'Money for order :order has been returned',
            'status' => 'Your payment for order :order arrived after the order was closed, and the money has been returned.',
            'next' => 'When it arrives depends on your bank or payment method. Contact customer service if you have not received it.',
        ],
        'refund_submitted' => [
            'subject' => 'Refund request :ref received',
            'status' => 'We have received refund request :ref for order :order.',
            'next' => 'Send your unboxing video to the Tuffero admin WhatsApp or email and mention number :ref. Customer service will contact you.',
        ],
        'refund_approved' => [
            'subject' => 'Refund :ref approved',
            'status' => 'Refund request :ref for order :order was approved.',
            'next' => 'Customer service will contact you through official Tuffero channels to complete the refund.',
        ],
        'refund_rejected' => [
            'subject' => 'Refund :ref was not approved',
            'status' => 'Refund request :ref for order :order was not approved.',
            'next' => 'Contact customer service if you have questions about this decision.',
        ],
        'refund_evidence_insufficient' => [
            'subject' => 'Unboxing video for refund :ref needs to be completed',
            'status' => 'The unboxing video for refund :ref is incomplete, so the request cannot move forward yet.',
            'next' => 'Send a clear video again to the Tuffero admin WhatsApp or email and mention number :ref: starting from the tracking number, with the package still sealed, through to the product inside.',
        ],
        'refund_paid_manual_transfer' => [
            'subject' => 'Refund :ref has been transferred',
            'status' => 'The money for refund :ref for order :order has been transferred.',
            'next' => 'When it arrives depends on your bank. Contact customer service if you have not received it.',
        ],
        'refund_paid_resend' => [
            'subject' => 'Replacement for refund :ref has been processed',
            'status' => 'Refund :ref for order :order was settled by sending the item again.',
            'next' => 'Contact customer service for delivery details of the replacement.',
        ],
        'refund_paid_voucher' => [
            'subject' => 'Replacement voucher for refund :ref has been issued',
            'status' => 'Refund :ref for order :order was settled with a replacement voucher.',
            'next' => 'Customer service will give you the voucher details. Contact them if you have not received it.',
        ],
    ],
];
