# SCTP Library for PHP

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-BSD-blue.svg)](LICENSE)

A PHP library implementing SCTP (Stream Control Transmission Protocol) for WebRTC data channels. This package supports stream multiplexing, retransmissions, congestion control, and reliable or partially reliable data delivery.

## About this fork

This is the `danog/php-rtc-sctp` fork used by MadelineProto. It targets PHP 8.2+ and replaces ReactPHP promises and timers with blocking Amp v3 fiber APIs and Revolt timers. An SCTP association can also run independently of an RTCDataChannel.

The forked stack keeps the upstream `quasarstream/*` dependency constraints for compatibility. Each `danog/php-rtc-*` package replaces its upstream counterpart, so consumers select the complete maintained stack by requiring the corresponding danog packages together.

##  Features

- Full support for SCTP packet parsing and serialization
- Stream multiplexing for data channels
- Congestion control and retransmission mechanisms
- Timer-based delivery (RFC 3758)
- Partial reliability support


## Requirements

- **PHP ≥ 8.2**

## Documentation

This package is part of the PHP WebRTC library. For complete documentation, examples, and API reference, visit:

[PHP WebRTC Documentation](https://www.quasarstream.com/php-webrtc)

## Credits

### Authors

- **Amin Yazdanpanah**  
  - Website: [aminyazdanpanah.com](https://www.aminyazdanpanah.com)
  - Email: [github@aminyazdanpanah.com](mailto:github@aminyazdanpanah.com)

- **Sana Moniri**  
  - GtiHub: [sanamoniri](https://github.com/sanamoniri)

## Reporting Issues

Found a bug? Please report it on our [issues](https://github.com/php-webrtc/sctp/issues).

## License

BSD 3-Clause License. See [LICENSE](LICENSE) for details.

## References

- [RFC 4960 – Stream Control Transmission Protocol](https://datatracker.ietf.org/doc/html/rfc4960)
- [RFC 8831 – WebRTC Data Channels](https://datatracker.ietf.org/doc/html/rfc8831)
- [RFC 3758 – SCTP Partial Reliability Extension](https://datatracker.ietf.org/doc/html/rfc3758)
