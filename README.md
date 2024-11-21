# Laravel Super Cache Invalidate

![Laravel Super Cache Invalidate](./resources/images/laravel-super-cache-invalidate-logo.webp)

**Laravel Super Cache Invalidate** is a powerful package that provides an efficient and scalable cache invalidation system for Laravel applications. It is designed to handle high-throughput cache invalidation scenarios, such as those found in e-commerce platforms, by implementing advanced techniques like event queuing, coalescing, debouncing, sharding, and partitioning.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-super-cache-invalidate.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-super-cache-invalidate)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![CircleCI](https://circleci.com/gh/padosoft/laravel-super-cache-invalidate.svg?style=shield)](https://circleci.com/gh/padosoft/laravel-super-cache-invalidate)
[![Quality Score](https://img.shields.io/scrutinizer/g/padosoft/laravel-super-cache-invalidate.svg?style=flat-square)](https://scrutinizer-ci.com/g/padosoft/laravel-super-cache-invalidate)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/laravel-super-cache-invalidate.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-super-cache-invalidate)

## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [Requires](#requires)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
    - [Inserting Invalidation Events](#inserting-invalidation-events)
    - [Processing Invalidation Events](#processing-invalidation-events)
    - [Pruning Old Data](#pruning-old-data)
- [Architecture and Techniques](#architecture-and-techniques)
    - [Event Queue for Cache Invalidation](#event-queue-for-cache-invalidation)
    - [Coalescing Invalidation Events](#coalescing-invalidation-events)
    - [Debouncing Mechanism](#debouncing-mechanism)
    - [Sharding and Parallel Processing](#sharding-and-parallel-processing)
    - [Partitioning Tables](#partitioning-tables)
    - [Semaphore Locking with Redis](#semaphore-locking-with-redis)
- [Performance Optimizations](#performance-optimizations)
- [Testing](#testing)
- [Contributing](#contributing)
- [License](#license)

## Introduction

In high-traffic applications, especially e-commerce platforms, managing cache invalidation efficiently is crucial. Frequent updates from various sources like ERP systems, warehouses, backoffice tools, and web orders can lead to performance bottlenecks if not handled properly. This package provides a robust solution by implementing advanced cache invalidation strategies.

## Features

- **Asynchronous Event Queue**: Collect and process cache invalidation requests asynchronously.
- **Coalescing and Debouncing**: Merge multiple invalidation events and prevent redundant invalidations.
- **Sharding**: Distribute events across shards for parallel processing.
- **Partitioning**: Use MySQL partitioning for efficient data management and purging.
- **Semaphore Locking**: Prevent overlapping processes using Redis locks.
- **Customizable**: Configure invalidation windows, shard counts, batch sizes, and more.
- **High Performance**: Optimized for handling millions of events with minimal overhead.

## Requires

- php: >=8.0
- illuminate/database: ^9.0|^10.0|^11.0
- illuminate/support:  ^9.0|^10.0|^11.0


## Installation

Install the package via Composer:

```bash
composer require padosoft/laravel-super-cache-invalidate
```
Publish the configuration and migrations:

```bash
php artisan vendor:publish --provider="Padosoft\SuperCacheInvalidate\SuperCacheInvalidationServiceProvider"
```

Run migrations:

```bash
php artisan migrate
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Testing

``` bash
composer test
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security related issues, please email instead of using the issue tracker.

## Credits
- [Lorenzo Padovani](https://github.com/lopadova)
- [All Contributors](../../contributors)

## About Padosoft
Padosoft (https://www.padosoft.com) is a software house based in Florence, Italy. Specialized in E-commerce and web sites.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
