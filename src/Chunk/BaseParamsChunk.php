<?php declare(strict_types=1);

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Chunk;

use Override;
use Webrtc\SCTP\SctpUtility;

/**
 * Base class for chunks that contain parameters.
 */
class BaseParamsChunk extends Chunk
{
    /** @var array<array-key, array{0: int, 1: string}> List of parameters. */
    protected array $params;

    /**
     * BaseParamsChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        $this->params = ($body !== null && $body !== "") ? SctpUtility::decodeParams($body) : [];
    }

    /** @return array<array-key, array{0: int, 1: string}> */
    public function getParams(): array
    {
        return $this->params;
    }

    /** @param array<array-key, array{0: int, 1: string}> $params */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Add params and merge with existing ones.
     *
     * @param array<array-key, array{0: int, 1: string}> $param
     */
    public function addParams(array $param): void
    {
        $this->params = array_merge($this->params, $param);
    }

    /**
     * Gets the encoded body of the chunk.
     *
     * @return string Encoded body.
     */
    #[Override]
    public function getBody(): string
    {
        return SctpUtility::encodeParams($this->params);
    }
}
