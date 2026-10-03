<?php

// Helpers shared by the audit tests

/**
 * Run the requests [[Browser, path, delay seconds, method, body]], each started $delay seconds
 * after the first; the decoded JSON bodies in order, with the HTTP status.
 */
function audr_overlap(array $requests): array
{
    $multi   = \curl_multi_init();
    $handles = $responses = [];
    $start   = \microtime(true);
    $pending = $requests;
    do {
        foreach ($pending as $i => $r) {
            [$browser, $path, $delay] = $r;
            if (\microtime(true) - $start >= $delay) {
                $handles[$i] = \curl_init("http://{$browser->addr}$path");
                \curl_setopt_array($handles[$i], $browser->options($r[3] ?? 'GET', $r[5] ?? [], $r[4] ?? null, $responses[$i]));
                \curl_multi_add_handle($multi, $handles[$i]);
                unset($pending[$i]);
            }
        }
        \curl_multi_exec($multi, $running);
        \curl_multi_select($multi, 0.02);
    } while ($running > 0 || $pending);
    $out = [];
    foreach ($handles as $i => $ch) {
        $body    = \curl_multi_getcontent($ch);
        $out[$i] = (\json_decode($body, true) ?? ['body' => $body]) + ['status' => \curl_getinfo($ch, \CURLINFO_RESPONSE_CODE)];
        \curl_multi_remove_handle($multi, $ch);
    }
    \ksort($out);

    return $out;
}

/** A's window contains B's: B started and ended while A was waiting. */
function audr_nested(array $a, array $b): void
{
    expect($a['start'] < $b['start'] && $b['end'] < $a['end'])->toBeTrue('requests did not overlap (needs phasync-ext): ' . \json_encode([$a['start'], $a['end'], $b['start'], $b['end']]));
}
