<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

/**
 * Receiver for reassembled messages of a raw signaling association.
 *
 * tgcalls runs its signaling channel over a plain SCTP association that never negotiates a
 * data channel: it opens stream 0 and sends user data on it. A sink installed with
 * {@see RTCSctpTransport::setSignalingSink()} receives that user data directly, bypassing the
 * data channel layer.
 *
 * The sink is part of the transport's serializable state, so it must be a real, serializable
 * invokable object — never a Closure, which cannot be serialized. Requiring this interface
 * (rather than an arbitrary callable) makes that contract explicit at the type level: the
 * consumer implements it on an ordinary object that survives a serialize cycle.
 */
interface SignalingSinkInterface
{
    /**
     * Handle one reassembled signaling message.
     *
     * @param string $data The reassembled user data received on the signaling stream.
     */
    public function __invoke(string $data): void;
}
