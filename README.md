<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About This Project

This Laravel application manages Safaricom M-PESA C2B (Customer-to-Business) transactions, including callback data processing and STK push service. It also supports C2B URL registration. The application stores M-PESA callback data and provides API endpoints for external systems to register callback URLs and fetch stored data.

Feel free to adjust the content to better fit the specifics of your project - Harold Kerry 😊

## My Contact
If you have any questions or need further assistance, feel free to reach out:

Email: haroldkerry@gmail.com
Website: haroldkerry.co.ke



### Features

- **M-PESA Callback Handling**: Receives and processes M-PESA C2B callback data.
- **C2B URL Registration**: Allows clients to register their confirmation and validation URLs with M-PESA.
- **Data Storage**: Stores callback data in a MySQL database.
- **Data Fetching Endpoint**: Exposes an API endpoint for external systems to retrieve stored callback data.
- **M-PESA STK Service**: Allows interaction with M-PESA's STK push feature to initiate payments.

### Project Structure

- **Controllers**: Manage incoming requests and responses.
  - `MpesaCallbackController`: Handles M-PESA callback and validation requests, and manages URL registration.
  - `MpesaDataFetchController`: Fetches stored M-PESA callback data.

- **Services**: Encapsulate business logic.
  - `MpesaCallBackService`: Processes and saves M-PESA callback data.
  - `MpesaAuthService`: Handles M-PESA access token generation.
  - `MpesaCallbackRegistrationService`: Manages M-PESA C2B URL registration.

- **Models**: Represent the database tables.
  - `MpesaConfirmation`: Eloquent model for storing callback data.

- **Routes**: Define the API endpoints.
  - `POST /payments/confirmation/callback`: Endpoint for M-PESA confirmation callback.
  - `POST /payments/validation/callback`: Endpoint for M-PESA validation callback.
  - `POST /mpesa/callback/register`: Endpoint for registering confirmation and validation URLs.
  - `GET /mpesa/payments/c2b`: Endpoint to fetch stored M-PESA callback data filtered by shortcode.
  - `POST /mpesa/stk/initiate: Endpoint to initiate an STK (Sim Toolkit) request with M-PESA.
  - `POST /mpesa/stk/callback: Endpoint to handle the callback data from M-PESA STK request.
  - `GET /mpesa/payments/stk: Endpoint to fetch M-PESA STK payments from the database filtered by shortcode.

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
  - The `/payments/confirmation/callback` endpoint will receive callback data from M-PESA and store it in the database.

- **M-PESA Validation**:
  - The `/payments/validation/callback` endpoint responds to M-PESA with a JSON response to accept or reject transactions.

- **C2B URL Registration**:
  - The `/mpesa/callback/register` endpoint allows clients to register their confirmation and validation URLs with M-PESA.

- **Fetch Stored Data**:
  - The `/mpesa/records/fetch` endpoint allows external systems to fetch stored callback data.

### Contributing

If you'd like to contribute to this project, please submit a pull request with a description of the changes.
