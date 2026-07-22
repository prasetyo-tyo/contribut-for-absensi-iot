<?php

define('CARD_INTERNAL_UID_SECRET', 'kp-card-internal-2026-v1');
define('CARD_DEVICE_API_SECRET', 'kp-device-api-2026-v1');
define('CAMERA_TRIGGER_SECRET', 'kp-camera-trigger-2026-v1');
define('CAMERA_UPLOAD_SECRET', 'kp-camera-upload-2026-v1');
define('CARD_SIGNATURE_TTL_SECONDS', 300);

function card_normalize_value($value)
{
    $value = strtoupper(trim((string) $value));
    return preg_replace('/[^A-Z0-9]/', '', $value);
}

function card_build_internal_uid($physicalUid, $cardToken)
{
    $physicalUid = card_normalize_value($physicalUid);
    $cardToken = card_normalize_value($cardToken);

    return hash('sha256', $physicalUid . '|' . $cardToken . '|' . CARD_INTERNAL_UID_SECRET);
}

function card_build_signature_payload($physicalUid, $cardToken, $timestamp)
{
    $physicalUid = card_normalize_value($physicalUid);
    $cardToken = card_normalize_value($cardToken);

    return $physicalUid . '|' . $cardToken . '|' . (string) $timestamp;
}

function card_build_request_signature($physicalUid, $cardToken, $timestamp)
{
    return hash_hmac(
        'sha256',
        card_build_signature_payload($physicalUid, $cardToken, $timestamp),
        CARD_DEVICE_API_SECRET
    );
}

function card_verify_request_signature($physicalUid, $cardToken, $timestamp, $signature)
{
    if (!ctype_digit((string) $timestamp)) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > CARD_SIGNATURE_TTL_SECONDS) {
        return false;
    }

    $expected = card_build_request_signature($physicalUid, $cardToken, $timestamp);
    return hash_equals($expected, strtolower((string) $signature));
}

function camera_build_trigger_payload($absenId, $uid, $status, $timestamp)
{
    return (int) $absenId . '|' . strtolower(trim((string) $uid)) . '|' . strtoupper(trim((string) $status)) . '|' . (string) $timestamp;
}

function camera_build_trigger_signature($absenId, $uid, $status, $timestamp)
{
    return hash_hmac(
        'sha256',
        camera_build_trigger_payload($absenId, $uid, $status, $timestamp),
        CAMERA_TRIGGER_SECRET
    );
}

function camera_verify_trigger_signature($absenId, $uid, $status, $timestamp, $signature)
{
    if (!ctype_digit((string) $timestamp) || (int) $absenId <= 0) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > CARD_SIGNATURE_TTL_SECONDS) {
        return false;
    }

    $expected = camera_build_trigger_signature($absenId, $uid, $status, $timestamp);
    return hash_equals($expected, strtolower((string) $signature));
}

function camera_build_upload_signature($absenId, $uid, $status, $timestamp)
{
    return hash_hmac(
        'sha256',
        camera_build_trigger_payload($absenId, $uid, $status, $timestamp),
        CAMERA_UPLOAD_SECRET
    );
}

function camera_build_job_payload($outletId, $timestamp)
{
    return (int) $outletId . '|' . (string) $timestamp;
}

function camera_build_job_signature($outletId, $timestamp)
{
    return hash_hmac(
        'sha256',
        camera_build_job_payload($outletId, $timestamp),
        CAMERA_TRIGGER_SECRET
    );
}

function camera_verify_job_signature($outletId, $timestamp, $signature)
{
    if (!ctype_digit((string) $timestamp) || (int) $outletId <= 0) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > CARD_SIGNATURE_TTL_SECONDS) {
        return false;
    }

    $expected = camera_build_job_signature($outletId, $timestamp);
    return hash_equals($expected, strtolower((string) $signature));
}

function camera_verify_upload_signature($absenId, $uid, $status, $timestamp, $signature)
{
    if (!ctype_digit((string) $timestamp) || (int) $absenId <= 0) {
        return false;
    }

    if (abs(time() - (int) $timestamp) > CARD_SIGNATURE_TTL_SECONDS) {
        return false;
    }

    $expected = camera_build_upload_signature($absenId, $uid, $status, $timestamp);
    return hash_equals($expected, strtolower((string) $signature));
}
