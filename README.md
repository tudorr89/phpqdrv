# Simple PHP Queue Package

A flexible PHP queue management system supporting multiple backend drivers.

## 🚀 Features

- Multiple queue backend support
  - Redis
  - MariaDB
  - PostgreSQL
  - SQLite
  - Beanstalkd
- Robust job processing
- Configurable retry mechanisms
- Extensible architecture

## 📦 Installation

Install the package using Composer:

```bash
composer require tudorr89/phpqdrv
```

## 🔧 Requirements

- PHP 8.1+
- Supported database extensions based on chosen driver

## 💡 Usage Examples

### Redis Queue

```php
<?php
use Predis\Client;
use Tudorr89\Phpqdrv\QueueFactory;
use Tudorr89\Phpqdrv\Worker;

// Create Redis connection
$redis = new Client([
    'host' => '127.0.0.1',
    'port' => 6379
]);

// Create queue instance
$queue = QueueFactory::createRedisQueue($redis);

// Enqueue a job
$job = $queue->push('emails', [
    'to' => 'user@example.com',
    'subject' => 'Welcome',
    'body' => 'Hello World!'
]);

// Create a worker
$worker = new Worker($queue);

// Process jobs
$worker->work('emails', function($payload) {
    sendEmail(
        $payload['to'],
        $payload['subject'],
        $payload['body']
    );
});
```

### PostgreSQL Queue

```php
<?php
use PDO;
use Tudorr89\Phpqdrv\QueueFactory;
use Tudorr89\Phpqdrv\Worker;

// Create PDO connection
$pdo = new PDO(
    'pgsql:host=localhost;dbname=mydb',
    'username',
    'password'
);

// Create queue instance
$queue = QueueFactory::createPostgreSQLQueue($pdo);

// Similar job enqueuing and processing as Redis example
```

### Beanstalkd Queue

```php
<?php
use Net_Beanstalkd;
use Tudorr89\Phpqdrv\QueueFactory;
use Tudorr89\Phpqdrv\Worker;

// Create Beanstalkd connection
$beanstalkd = new Net_Beanstalkd('localhost', 11300);

// Create queue instance
$queue = QueueFactory::createBeanstalkdQueue($beanstalkd);

// Similar job enqueuing and processing as previous examples
```

## 🛠 Advanced Configuration

### Worker Configuration

```php
// Customize worker behavior
$worker = new Worker(
    $queue,
    $maxAttempts = 3,     // Maximum job retry attempts
    $sleepTime = 5        // Seconds to wait between job checks
);
```

## 📊 Queue Methods

Each queue driver implements these core methods:

- `push(string $queue, array $payload)`: Add a new job to the queue
- `pop(string $queue)`: Retrieve and remove a job from the queue
- `ack(JobInterface $job)`: Acknowledge successful job completion
- `fail(JobInterface $job)`: Mark a job as failed
- `count(string $queue)`: Count pending jobs in a queue

## 🔒 Error Handling

- Configurable max retry attempts
- Automatic job failure after max attempts
- Supports logging and custom error handling

## 📋 Planned Features

- [ ] Improved logging
- [ ] More sophisticated retry strategies
- [ ] Advanced job scheduling
- [ ] Distributed queue support

## 🤝 Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/AmazingFeature`)
3. Commit your changes (`git commit -m 'Add some AmazingFeature'`)
4. Push to the branch (`git push origin feature/AmazingFeature`)
5. Open a Pull Request

## 📜 License

Distributed under the MIT License. See `LICENSE` for more information.

## 🛟 Support

If you encounter any issues or have questions, please file an issue on the GitHub repository.