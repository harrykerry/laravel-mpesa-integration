<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About This Project

This Laravel application handles Safaricom M-PESA C2B callback data. It provides a mechanism to store callback data from M-PESA and exposes an endpoint for external systems to fetch the stored data. 

### Features

- **M-PESA Callback Handling**: Receives and processes M-PESA C2B callback data.
- **Data Storage**: Stores callback data in a MySQL database.
- **Data Fetching Endpoint**: Provides an API endpoint for external systems to retrieve previously stored data.

### Project Structure

- **Controllers**: Manage incoming requests and responses.
  - `MpesaCallbackController`: Handles the callback and validation requests from M-PESA.

- **Services**: Encapsulate business logic.
  - `MpesaCallBackService`: Processes and saves M-PESA callback data.

- **Models**: Represent the database tables.
  - `MpesaConfirmation`: Eloquent model for storing callback data.

- **Routes**: Define the API endpoints.
  - `POST /v1/mpesaConfirmation/callback`: Endpoint for M-PESA callback.
  - `POST /v1/mpesaValidation/callback`: Endpoint for M-PESA validation.

### Installation

1. Clone the repository:

2. Navigate to the project directory:

3. Install dependencies:
   ```bash
   composer install
   ```
4. Set up your environment file:
   ```bash
   cp .env.example .env
   ```
5. Generate the application key:
   ```bash
   php artisan key:generate
   ```
6. Run the migrations:
   ```bash
   php artisan migrate
   ```

### Usage

- **M-PESA Callback Handling**:
  - The `/v1/mpesaConfirmation/callback` endpoint will receive callback data from M-PESA and store it in the database.

- **M-PESA Validation**:
  - The `/v1/mpesaValidation/callback` endpoint responds to M-PESA with a JSON response indicating whether the validation is accepted or rejected.Check Daraja Docs on how to respond to reject a transaction

- **Fetch Stored Data**:
  - Implement an endpoint to fetch stored callback data based on your requirements.

### Contributing

If you'd like to contribute to this project, please submit a pull request with a description of the changes.

Feel free to adjust the content to better fit the specifics of your project. 😊