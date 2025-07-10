# FlexKit Ten Backend

A PHP backend API for FlexKit Ten application that integrates with Mindbody API to provide fitness studio management capabilities including class scheduling, client management, and contract purchases.

## Features

- **Mindbody API Integration**: Seamless integration with Mindbody's REST API
- **Client Management**: Create, update, and manage client information
- **Purchase System**: Handle contract purchases and service bookings
- **OTP Authentication**: Secure one-time password authentication via email
- **Timetable Management**: Fetch and manage class schedules
- **Session Management**: Secure session handling for authenticated users
- **Email Services**: Email notifications using Resend API
- **Logging**: Comprehensive logging system for debugging and monitoring

## Requirements

- PHP 8.0 or higher
- Composer (for dependency management)
- Web server (Apache/Nginx)
- Access to Mindbody API credentials

## Installation

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd ten-backend
   ```

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Set up environment variables:**
   ```bash
   cp .env.example .env
   ```

4. **Configure your `.env` file:**
   Edit the `.env` file with your actual configuration values:
   ```env
   # Mindbody API Configuration
   MINDBODY_API_KEY=your_api_key_here
   MINDBODY_SITE_ID=your_site_id_here
   MINDBODY_PASSWORD=your_password_here
   
   # Email Configuration
   RESEND_API_KEY=your_resend_api_key_here
   
   # Database Configuration (if using database)
   DB_HOST=localhost
   DB_NAME=flexkit_ten
   DB_USER=root
   DB_PASS=your_password
   ```

5. **Set up permissions:**
   ```bash
   mkdir -p logs
   chmod 755 logs
   ```

6. **Start the development server:**
   ```bash
   php -S localhost:8000 -t .
   ```

## Project Structure

```
ten-backend/
├── src/
│   ├── Config/
│   │   └── AppConfig.php          # Application configuration
│   ├── Controllers/               # API controllers (to be implemented)
│   ├── Middleware/               # Custom middleware
│   ├── Models/                   # Data models
│   └── Services/
│       ├── Database.php          # Database service
│       ├── EmailService.php      # Email service using Resend
│       ├── Logger.php            # Logging service
│       ├── MindbodyAPI.php       # Mindbody API integration
│       ├── OTPService.php        # OTP generation and validation
│       ├── Router.php            # API routing
│       ├── SessionService.php    # Session management
│       └── TimetableService.php  # Timetable management
├── templates/
│   └── otp_email.html           # OTP email template
├── logs/                        # Application logs
├── .env.example                 # Environment variables template
├── .gitignore                   # Git ignore rules
├── composer.json                # PHP dependencies
├── index.php                    # Application entry point
└── README.md                    # This file
```

## Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_NAME` | Application name | No |
| `APP_ENV` | Environment (development/production) | No |
| `APP_DEBUG` | Enable debug mode | No |
| `SERVER_HOST` | Server hostname | No |
| `SERVER_PORT` | Server port | No |
| `DB_HOST` | Database host | No |
| `DB_NAME` | Database name | No |
| `DB_USER` | Database username | No |
| `DB_PASS` | Database password | No |
| `MINDBODY_API_KEY` | Mindbody API key | **Yes** |
| `MINDBODY_SITE_ID` | Mindbody site ID | **Yes** |
| `MINDBODY_SOURCE_NAME` | Mindbody source name | No |
| `MINDBODY_PASSWORD` | Mindbody password | **Yes** |
| `LOG_LEVEL` | Logging level | No |
| `LOG_FILE` | Log file path | No |
| `RESEND_API_KEY` | Resend API key for emails | **Yes** |
| `RESEND_FROM_EMAIL` | From email address | No |
| `RESEND_FROM_NAME` | From name for emails | No |

## Development

### Running in Development Mode

1. Set `APP_ENV=development` in your `.env` file
2. Set `APP_DEBUG=true` for detailed error messages
3. Monitor logs in the `logs/` directory

### Testing API Endpoints

You can use tools like Postman, curl, or any HTTP client to test the API endpoints. A Postman collection may be available in `postman.json`.

### Logging

The application uses a custom logging service that writes to files in the `logs/` directory. Log levels include:
- `debug`: Detailed debug information
- `info`: General information
- `warning`: Warning messages
- `error`: Error messages

## License

This project is proprietary software. All rights reserved.

## Support

For support and questions, please contact the development team. 