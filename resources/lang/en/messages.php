<?php

declare(strict_types=1);

/**
 * English copy for the Payment plugin — read as `payment::messages.<key>`.
 *
 * A project overrides one line by defining the same key in its own lang files;
 * groups merge across the project-first cascade.
 *
 * validation.market / .amount / .phone / .payment / .bank_account are deliberately ABSENT here:
 * in English the precise message the domain raised ("UGX amounts allow at most
 * 0 decimal place(s).") is better than any generic line, and a key defined here
 * would replace it. Other locales translate them generically (see fr).
 */
return [
    // Shown to the PERSON PAYING — never the provider's own wording, which can
    // describe the business's account. That wording is logged instead.
    'rejected'             => 'The payment could not be processed. Please try again later.',
    'rejected_invalid'     => 'The payment details were not accepted. Check them and try again.',
    'rejected_phone'       => 'This phone number cannot be used for this payment.',
    'outcome_unknown'      => 'The payment status is not known yet. It will be confirmed automatically.',
    'already_pending'      => 'A payment for this is already in progress.',
    'already_paid'         => 'This has already been paid.',
    'payout_limit'         => 'Payouts are limited to :limit :currency each.',
    'payout_daily_limit'   => 'This payout would exceed the daily payout limit of :limit :currency.',
    'throttled'            => 'Too many requests for this payment. Try again in a few seconds.',
    'rate_limited'         => 'Too many status checks. Try again in a minute.',
    'payout_in_flight'     => 'Another payout is still being processed. Try again once it completes.',
    'provider_unavailable' => 'The payment provider could not be reached. The payment is pending and will be confirmed automatically.',
    'provider_unreachable' => 'The payment provider could not be reached. Check the transaction status before trying again.',
    'unknown_provider'     => 'Unknown payment provider [:provider].',
    'not_found'            => 'Payment [:reference] was not found.',
    'invalid_signature'    => 'Invalid webhook signature.',
    'forbidden'            => 'You are not allowed to manage payments.',
    'phone_not_found'      => 'That phone number is not saved.',
    'phone_not_verified'   => 'Verify this phone number before withdrawing to it.',
    'phone_failed'         => 'This phone number could not be verified, so money cannot be sent to it.',
    'phone_limit'          => 'At most :max phone numbers can be saved. Remove one first.',
    'lookup_limit'         => 'Too many phone number checks today. Try again tomorrow.',
    'not_awaiting_approval' => 'This withdrawal is not waiting for approval (it is :status).',
    'approver_required'    => 'A signed-in administrator must approve withdrawals.',
    'own_withdrawal'       => 'You cannot approve or reject your own withdrawal.',
    'approval_required'    => 'Money out must be approved by an administrator — use withdraw(), payout() or transfer().',
    'requester_required'   => 'Sign in to request a withdrawal.',
    'payout_minimum'       => 'The smallest amount that can be sent is :minimum :currency.',
    'payout_currency'      => 'Payouts in :currency are not enabled.',
    'phone_name_mismatch'  => 'This phone number is registered to someone else.',
    'bank_transfer_unavailable' => 'Bank transfers are not available in :country.',

    'validation' => [
        'method'          => 'Unsupported payment method [:method].',
        'phone_required'  => 'A phone number is required.',
        'too_long'        => 'Must be at most :max characters.',
        'metadata_count'  => 'At most :max metadata entries are allowed.',
        'metadata'        => 'Metadata must be flat key → value pairs (keys up to 40 characters, values up to 255).',
        'required'        => 'This field is required.',
        'reference'       => 'Invalid reference.',
        'utility'         => 'Unsupported utility [:utility]. Use LIGHT, NWSC, DSTV or GOTV.',
        'bouquet_utility' => 'Bouquets exist for dstv and gotv only.',
        'status'          => 'Unknown payment status.',
        'direction'       => 'Direction must be collection or payout.',
        'pii_keys'        => 'Every PII key must be one of the metadata keys.',
        'https'           => 'The URL must use https.',
        'action'          => 'Unknown action [:action].',
        'label'           => 'The label may be at most 60 characters.',
        'owner'           => 'The owner is required.',
    ],
];
