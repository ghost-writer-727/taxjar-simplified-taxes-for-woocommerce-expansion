# Webhooker

Webhooker is a simple PHP class designed to simplify the process of sending data to Zapier and other webhook services. This class is perfect for beginner programmers who are looking to integrate their PHP applications with Zapier.

## Installation

To use this class, you need to include it in your PHP project. You can do this by copying the `Webhooker` class into a `.php` file in your project.

## Usage

The `Webhooker` class has a static method `send` that you can use to send data to a webhook URL.

Simple Usage Example:
```php
// Include the Webhooker class file
require_once('Webhooker.php');

// Your Zapier webhook URL
$webhookUrl = 'https://hooks.zapier.com/hooks/catch/1234567/abcdefg/';

// The data you want to send to Zapier
$data = [
    'name' => 'John Doe',
    'email' => 'john.doe@example.com'
];

// Send the data to Zapier and get the response
$response = Webhooker::send($webhookUrl, $data);
```

Usage example with Try/Catch for errors:
```php
// Include the Webhooker class file
require_once('Webhooker.php');

// Your Zapier webhook URL
$webhookUrl = 'https://hooks.zapier.com/hooks/catch/1234567/abcdefg/';

// The data you want to send to Zapier
$data = [
    'name' => 'John Doe',
    'email' => 'john.doe@example.com'
];

try {

    // Send the data to Zapier and get the response
    $response = Webhooker::send($webhookUrl, $data);

    // Check if the request was successful
    if ($response['success']) {
        echo 'Data sent to Zapier successfully.';
    } else {
        echo 'Failed to send data to Zapier. HTTP Code: ' . $response['http_code'];
    }

} catch (InvalidArgumentException $e) {

    // Handle InvalidArgumentException here
    echo "An InvalidArgumentException was thrown: " . $e->getMessage();

} catch (Exception $e) {
    
    // Handle general exceptions here
    echo "An Exception was thrown: " . $e->getMessage();

}

```

In this example, replace `'https://hooks.zapier.com/hooks/catch/1234567/abcdefg/'` with your actual Zapier webhook URL and `$data` with the actual data you want to send to Zapier.

The `send` method returns an array with three keys:

- `success`: A boolean indicating whether the request was successful or not. It's `true` if the HTTP status code is 200, otherwise it's `false`.
- `response`: The response from Zapier.
- `http_code`: The HTTP status code of the response.

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## License

[MIT License](https://opensource.org/licenses/MIT)
