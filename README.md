# Laravel Super Cache

![Laravel Super Cache](./resources/images/laravel-super-cache-logo.webp)

A powerful caching solution for Laravel that uses Redis with Lua scripting, batch processing, and optimized tag management to handle high volumes of keys efficiently.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/padosoft/laravel-super-cache.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-super-cache)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![CircleCI](https://circleci.com/gh/padosoft/laravel-super-cache.svg?style=shield)](https://circleci.com/gh/padosoft/laravel-super-cache)
[![Quality Score](https://img.shields.io/scrutinizer/g/padosoft/laravel-super-cache.svg?style=flat-square)](https://scrutinizer-ci.com/g/padosoft/laravel-super-cache)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/laravel-super-cache.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-super-cache)

## Table of Contents

- [Purpose](#purpose)
- [Why Use This Package?](#why-use-this-package)
- [Features](#features)
- [Requires](#requires)
- [Installation and Configuration](#installation-and-configuration)
    - [Activating the Listener for Expiry Notifications](#activating-the-listener-for-expiry-notifications)
- [Usage Examples](#usage-examples)
- [Architecture Overview](#architecture-overview)
- [Design Decisions and Performance](#design-decisions-and-performance)
    - [Key and Tag Organization](#key-and-tag-organization)
    - [Sharding for Efficient Tag Management](#sharding-for-efficient-tag-management)
    - [Locks for Concurrency Control](#locks-for-concurrency-control)
    - [Handling Expire Correctly with TTLs](#handling-expire-correctly-with-ttls)
    - [Namespace Suffix for Parallel Processing](#namespace-suffix-for-parallel-processing)
    - [Scheduled Command for Orphaned Key Cleanup](#scheduled-command-for-orphaned-key-cleanup)

## Purpose

`laravel-super-cache` is designed to provide a high-performance, reliable, and scalable caching solution for Laravel applications that require efficient tag-based cache invalidation. By leveraging Redis's native capabilities and optimizing the way tags are managed, this package addresses limitations in Laravel's built-in cache tag system, making it suitable for large-scale enterprise applications.

## Why Use This Package?

Laravel's native caching mechanism has an implementation for tag-based cache management; however, it faces limitations, especially when dealing with high volumes of cache keys and frequent invalidations. Some of the issues include:

1. **Inconsistency with Tag Invalidation**: Laravel uses a versioned tag strategy, which can lead to keys not being properly invalidated when associated tags change, particularly in highly concurrent environments.
2. **Performance Overhead**: The default implementation in Laravel relies on a "soft invalidate" mechanism that increments tag versions instead of directly removing keys, which can lead to slower cache operations and memory growth (memory leaks).
3. **Scalability Issues**: The handling of large volumes of tags and keys is not optimized for performance, making it difficult to maintain consistency and speed in enterprise-level applications.

`laravel-super-cache` addresses these limitations by providing an efficient, high-performance caching layer optimized for Redis, leveraging Lua scripting, and batch processing techniques.

## Features

- **Architecture for High Volume**: Designed to handle thousands of cache keys with rapid creation and deletion, without performance degradation.
- **Enterprise-Level Performance**: Uses Redis's native capabilities and optimizes cache storage and retrieval for high-speed operations.
- **Use of Lua Scripting for Efficiency**: Employs Lua scripts for atomic operations, reducing network round-trips and improving consistency.
- **Batch Processing and Pipelining**: Processes cache operations in batches, reducing overhead and maximizing throughput.
- **Parallel Processing with Namespaces**: Enables parallel processing of expiry notifications by using namespace suffixing and configurable listeners.

## Requires

- php: >=8.0
- illuminate/database: ^9.0|^10.0|^11.0
- illuminate/support:  ^9.0|^10.0|^11.0


## Installation and Configuration

To install `laravel-super-cache`, use Composer:

```bash
composer require padosoft/laravel-super-cache
```

After installing the package, publish the configuration file:

```bash 
php artisan vendor:publish --provider="Padosoft\SuperCache\SuperCacheServiceProvider"
```

The configuration file allows you to set:

- `prefix`: The prefix for all cache keys, preventing conflicts when using the same Redis instance.
- `connection`: The Redis connection to use, as defined in your `config/database.php`.
- `SUPERCACHE_NUM_SHARDS`: The number of shards to optimize performance for tag management.
- `retry_max`, `batch_size`, and `time_threshold`: Parameters to optimize batch processing and retries.

### Activating the Listener for Expiry Notifications
The listener is responsible for handling expired cache keys and cleaning up associated tags. 
To activate it, add the following command to your `supervisor` configuration:
    
```ini
[program:supercache_listener]
command=php artisan supercache:listener {namespace}
numprocs=5
```

You can run multiple processes in parallel by setting different {namespace} values. 
This allows the listener to handle notifications in parallel for optimal performance.

### Enabling Redis Expiry Notifications (required for listener)
To enable the expiry notifications required by `laravel-super-cache`, you need to configure Redis (or AWS ElastiCache) to send `EXPIRED` events. 
Here's how you can do it:

#### For a Standard Redis Instance

1. **Edit Redis Configuration**: Open the `redis.conf` file and set the `notify-keyspace-events` parameter to enable expiry notifications.

   ```conf
   notify-keyspace-events Ex
   ```

    This configuration enables notifications for key expirations (Ex), which is required for the listener to function correctly.

2. **Using Redis CLI**: Alternatively, you can use the Redis CLI to set the configuration without editing the file directly.

   ```bash
   redis-cli config set notify-keyspace-events Ex
   ```
This command will apply the changes immediately without needing a Redis restart.

#### For AWS ElastiCache Redis
If you are using Redis on AWS ElastiCache, follow these steps to enable expiry notifications:

1. **Access the AWS ElastiCache Console:**

   - Go to the ElastiCache dashboard on your AWS account.

2. **Locate Your Redis Cluster:**

   - Find your cluster or replication group that you want to configure.

3. **Modify the Parameter Group:**

   - Go to the **Parameter Groups** section.
   - Find the parameter group associated with your cluster, or create a new one.
   - Search for the `notify-keyspace-events` parameter and set its value to `Ex`.
   - Save changes to the parameter group.

4. **Attach the Parameter Group to Your Redis Cluster:**

   - Attach the modified parameter group to your Redis cluster.
   - A **cluster reboot** may be required for the changes to take effect.

After configuring the `notify-keyspace-events` parameter, Redis will publish `EXPIRED` events when keys expire, allowing the `laravel-super-cache` listener to process these events correctly.


### Scheduled Command for Orphaned Key Cleanup (Optionally but recommended)
Optionally but recommended, a scheduled command can be configured to periodically clean up any orphaned keys or sets left due to unexpected interruptions or errors. This adds an additional safety net to maintain consistency across cache keys and tags.

```php
php artisan supercache:clean
```

This can be scheduled using Laravel's scheduler to run at appropriate intervals, ensuring your cache remains clean and optimized.


## Usage Examples
Below are examples of using the `SuperCacheManager` and its facade `SuperCache`:

```php
use SuperCache\Facades\SuperCache;

// Store an item in cache
SuperCache::put('user:1', $user);

// Store an item in cache with tags
SuperCache::putWithTags('product:1', $product, ['products', 'featured']);

// Retrieve an item from cache
$product = SuperCache::get('product:1');

// Check if a key exists
$exists = SuperCache::has('user:1');

// Increment a counter
SuperCache::increment('views:product:1');

// Decrement a counter
SuperCache::decrement('stock:product:1', 5);

// Get all keys matching a pattern
$keys = SuperCache::getKeys(['product:*']);

// Flush all cache
SuperCache::flush();

```

For all options see `SuperCacheManager` class.


## Architecture Overview

![the-architecture.webp](resources%2Fimages%2Fthe-architecture.webp)


## Design Decisions and Performance

### Key and Tag Organization
![add key.webp](resources%2Fimages%2Fadd%20key.webp)
![remove key.webp](resources%2Fimages%2Fremove%20key.webp)
Each cache key is stored with an associated set of tags. These tags allow efficient invalidation when certain categories of keys need to be cleared. The structure ensures that any cache invalidation affects only the necessary keys without touching unrelated data.
## Tag-Based Cache Architecture

To efficiently handle cache keys and their associated tags, `laravel-super-cache` employs a well-defined architecture using Redis data structures. This ensures high performance for lookups, tag invalidations, and efficient management of keys. Below is a breakdown of how the package manages and stores these data structures in Redis.

### 3 Main Structures in Redis

1. **Key-Value Storage**:
    - Each cache entry is stored as a key-value pair in Redis.
    - **Naming Convention**: The cache key is prefixed for the package (e.g., `supercache:key:<actual-key>`), ensuring no conflicts with other Redis data.

2. **Set for Each Tag (Tag-Key Sets)**:
    - For every tag associated with a key, a Redis set is created to hold all the keys associated with that tag.
    - **Naming Convention**: Each set is named with a pattern like `supercache:tag:<tag>:shard:<shard-number>`, where `<tag>` is the tag name and `<shard-number>` is determined by the sharding algorithm.
    - These sets allow quick retrieval of all keys associated with a tag, facilitating efficient cache invalidation by tag.

3. **Set for Each Key (Key-Tag Sets)**:
    - Each cache key has a set that holds all the tags associated with that key.
    - **Naming Convention**: The set is named as `supercache:tags:<key>`, where `<key>` is the actual cache key.
    - This structure allows quick identification of which tags are associated with a key, ensuring efficient clean-up when a key expires.

### Sharding for Efficient Tag Management

To optimize performance when dealing with potentially large sets of keys associated with a single tag, `laravel-super-cache` employs a **sharding strategy**:

- **Why Sharding?**: A single tag might be associated with a large number of keys. If all keys for a tag were stored in a single set, this could degrade performance. Sharding splits these keys across multiple smaller sets, distributing the load.
- **How Sharding Works**: When a key is added to a tag, a fast hash function (e.g., `crc32`) is used to compute a shard index. The key is then stored in the appropriate shard for that tag.
    - **Naming Convention for Sharded Sets**: Each set for a tag is named as `supercache:tag:<tag>:shard:<shard-number>`.
    - The number of shards is configurable through the `SUPERCACHE_NUM_SHARDS` setting, allowing you to balance between performance and memory usage.

### Example: Creating a Cache Key with Tags

When you create a cache key with associated tags, here's what happens:

1. **Key-Value Pair**: A key-value pair is stored in Redis, prefixed as `supercache:key:<actual-key>`.
2. **Tag-Key Sets**: For each tag associated with the key:
    - A shard is determined using a hash function.
    - The key is added to the corresponding sharded set for the tag, named as `supercache:tag:<tag>:shard:<shard-number>`.
3. **Key-Tag Set**: A set is created to associate the key with its tags, named as `supercache:tags:<key>`.

This structure allows efficient lookup, tagging, and invalidation of cache entries.

#### Example
Suppose you cache a key `product:123` with tags `electronics` and `featured`. The following structures would be created in Redis:

- **Key-Value Pair**:
    - `supercache:key:product:123` -> `<value>`

- **Tag-Key Sets**:
    - Assuming `SUPERCACHE_NUM_SHARDS` is set to 256, and `product:123` hashes to shard `42` for `electronics` and shard `85` for `featured`:
        - `supercache:tag:electronics:shard:42` -> contains `supercache:key:product:123`
        - `supercache:tag:featured:shard:85` -> contains `supercache:key:product:123`

- **Key-Tag Set**:
    - `supercache:tags:product:123` -> contains `electronics`, `featured`

### Benefits of This Architecture

- **Efficient Lookups and Invalidation**: Using sets for both tags and keys enables quick lookups and invalidation of cache entries when necessary.
- **Scalable Performance**: The sharding strategy distributes the keys associated with tags across multiple sets, ensuring performance remains high even when a tag has a large number of keys.
- **Atomic Operations**: When a cache key is added or removed, all necessary operations (like updating sets and shards) are executed atomically, ensuring data consistency.

By following this architecture, `laravel-super-cache` is designed to handle high-volume cache operations efficiently while maintaining the flexibility and scalability needed for large enterprise applications.


### Sharding for Efficient Tag Management
![sharding.webp](resources%2Fimages%2Fsharding.webp)
Tags are distributed across multiple shards to optimize performance. 
When a key is associated with a tag, it is added to a specific shard determined by a fast hashing function (crc32). 
This sharding reduces the performance bottleneck by preventing single large sets from slowing down the cache operations.

### Locks for Concurrency Control
![locks.webp](resources%2Fimages%2Flocks.webp)
When a key is processed for expiration, an optimistic lock is acquired to prevent race conditions, ensuring that no concurrent process attempts to alter the same key simultaneously. The lock has a short TTL to ensure it is quickly released after processing.

### Namespace Suffix for Parallel Processing
To handle high volumes of expiry notifications efficiently, the listener processes them in parallel by suffixing namespaces to keys. Each process handles notifications for a specific namespace, allowing for scalable parallel processing. The listener uses batch processing with Lua scripting to optimize performance.

### Redis Expiry Notifications and Listener System

#### Handling Expire Correctly with TTLs
![expires.webp](resources%2Fimages%2Fexpires.webp)
To ensure keys are deleted from Redis when they expire, a combination of TTL and expiry notifications is used. If a key expires naturally, the listener is notified to clean up any associated tag sets. This ensures no "orphan" references are left in the cache.

#### Listener for Expiry Notifications
![listener.webp](resources%2Fimages%2Flistener.webp)
The listener is responsible for handling expired cache keys and cleaning up associated tags efficently by using mixed technics LUA script + batch processing + pipeline.
You can run multiple processes in parallel by setting different {namespace} values. This allows the listener to handle notifications in parallel for optimal performance.

To ensure that keys are cleaned up efficiently upon expiration, `laravel-super-cache` uses Redis expiry notifications in combination with a dedicated listener process. This section explains how the notification system works, how the listener consumes these events, and the benefits of using batching, pipelines, and Lua scripts for performance optimization.

#### Redis Expiry Notifications: How They Work

Redis has a mechanism to publish notifications when certain events occur. One of these events is the expiration of keys (`EXPIRED`). The `laravel-super-cache` package uses these expiry notifications to clean up the tag associations whenever a cache key expires.

**Enabling Expiry Notifications in Redis**:
- Redis must be configured to send `EXPIRED` notifications. This is done by setting the `notify-keyspace-events` parameter to include `Ex` (for expired events).
- When a key in Redis reaches its TTL (Time-To-Live) and expires, an `EXPIRED` event is published to the Redis notification channel.

#### The Listener: Consuming Expiry Events

The `supercache:listener` command is a long-running process that listens for these `EXPIRED` events and performs clean-up tasks when they are detected. Specifically, it:
1. **Subscribes to Redis Notifications**: The listener subscribes to the `EXPIRED` events, filtered by a specific namespace to avoid processing unrelated keys.
2. **Accumulates Expired Keys in Batches**: When a key expires, the listener adds it to an in-memory batch. This allows for processing multiple keys at once rather than handling each key individually.
3. **Processes Batches Using Pipelines and Lua Scripts**: Once a batch reaches a size or time threshold, it is processed in bulk using Redis pipelines or Lua scripts for maximum efficiency.

### Performance Benefits: Batching, Pipeline, and Lua Scripts

1. **Batching in Memory**:
    - **What is it?** Instead of processing each expired key as soon as it is detected, the listener accumulates keys in a batch.
    - **Benefits**: Reduces the overhead of individual operations, as multiple keys are processed together, reducing the number of calls to Redis.

2. **Using Redis Pipelines**:
    - **What is it?** A pipeline in Redis allows multiple commands to be sent to the server in one go, reducing the number of network round-trips.
    - **Benefits**: Processing a batch of keys in a single pipeline operation is much faster than processing each key individually, as it minimizes network latency.

3. **Executing Batch Operations with Lua Scripts**:
    - **What is it?** Lua scripts allow you to execute multiple Redis commands atomically, ensuring that all operations on a batch of keys are processed as a single unit within Redis.
    - **Benefits**:
        - **Atomicity**: Ensures that all related operations (e.g., removing a key and cleaning up its associated tags) happen together without interference from other processes.
        - **Performance**: Running a Lua script directly on the Redis server is faster than issuing multiple commands from an external client, as it reduces the need for multiple network calls and leverages Redis’s internal processing speed.

### Parallel Processing with Namespaces

To improve scalability, the listener allows processing multiple namespaces in parallel. Each listener process is assigned to handle a specific namespace, ensuring that the processing load is distributed evenly across multiple processes.

- **Benefit**: Parallel processing enables your system to handle high volumes of expired keys efficiently, without creating a performance bottleneck.

#### Example Workflow: Handling Key Expiry

1. **Key Expiration Event**: A cache key `product:123` reaches its TTL and expires. Redis publishes an `EXPIRED` event for this key.
2. **Listener Accumulates Key**: The listener process receives the event and adds `product:123` to an in-memory batch.
3. **Batch Processing Triggered**: Once the batch reaches a size threshold (e.g., 100 keys) or a time threshold (e.g., 1 second), the listener triggers the batch processing.
4. **Batch Processing with Lua Script**:
    - A Lua script is executed on Redis to:
        - Verify if the key is actually expired (prevent race conditions).
        - Remove the key from all associated tag sets.
        - Delete the key-tag association set.
    - This entire process is handled atomically by the Lua script for consistency and performance.

### Why This Approach Optimizes Performance
By combining batching, pipelining, and Lua scripts, the package ensures:

- **Reduced Network Overhead**: Fewer round-trips between your application and Redis.
- **Atomic Operations**: Lua scripts guarantee that all necessary operations for a key's expiry are handled in a single atomic block.
- **Efficient Resource Utilization**: Memory batching allows for efficient use of system resources, processing large numbers of keys quickly.
- **Parallel Scalability**: By using multiple listeners across namespaces, your system can handle large volumes of expirations without creating performance bottlenecks.
This approach provides a robust, scalable, and highly performant cache management system for enterprise-grade Laravel applications.

### Scheduled Command for Orphaned Key Cleanup
Optionally, a scheduled command can be configured to periodically clean up any orphaned keys or sets left due to unexpected interruptions or errors. 
This adds an additional safety net to maintain consistency across cache keys and tags.

```php
php artisan supercache:clean
```

This can be scheduled using Laravel's scheduler to run at appropriate intervals, ensuring your cache remains clean and optimized.


## Conclusion
With laravel-super-cache, your Laravel application can achieve enterprise-grade caching performance with robust tag management, efficient key invalidation, and seamless parallel processing. Enjoy a faster, more reliable cache that scales effortlessly with your application's needs.


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
