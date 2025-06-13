# Large File Transfer

A lightweight, single-file PHP solution for transferring large files between computers, specifically designed for small VMs and shared hosting environments. This application provides a simple way to transfer files of any size through a web interface, with minimal server requirements and no database dependencies.

## Purpose

This project was created to solve the challenge of transferring large files in resource-constrained environments:
- Works on small VMs with limited resources
- Compatible with shared hosting environments
- No database required - everything is file-based
- Single PHP file for easy deployment
- Minimal dependencies and setup required

## Components

- `largefiletransfer.php`: The main application file containing all the necessary code for file transfer functionality
- `run_largefiletransfer.sh`: A helper script for local development and testing in Docker (not required for production use)

## Features

- **Secure File Transfer**: Password-protected access to prevent unauthorized usage
- **Large File Support**: Handles files of any size through chunked transfer
- **Simple Interface**: Easy-to-use web interface for both senders and receivers
- **Progress Tracking**: Real-time progress monitoring during file transfers
- **Automatic Cleanup**: Temporary files are automatically removed after successful transfer
- **Resource Management**: Built-in checks for disk space and transfer limits

## Requirements

- PHP 7.0 or higher
- Web server (Apache, Nginx, etc.)
- Write permissions for the temporary directory
- No database required
- Minimal server resources needed

## Installation

1. Upload `largefiletransfer.php` to your web server directory
2. Ensure the web server has write permissions to the temporary directory
3. Access the application through your web browser
4. Set up the initial password when prompted

Note: The `run_largefiletransfer.sh` script is only needed if you want to test the application locally using Docker.

## Usage

### Sending Files

1. Log in with the password
2. Select "Send" mode
3. Choose the file you want to send
4. Wait for the upload to complete
5. Share the generated session ID with the receiver

### Receiving Files

1. Log in with the password
2. Select "Receive" mode
3. Enter the session ID provided by the sender
4. Wait for the download to complete
5. The file will be automatically assembled and offered for download

## Security

- All access is protected by a password
- Session IDs are randomly generated and not guessable
- Files are transferred in chunks and stored in a non-public directory
- Temporary files are automatically cleaned up after successful transfer
- Per-session directory size limits prevent server overload

## Configuration

The application can be configured by modifying the following variables in `largefiletransfer.php`:

- `$CHUNK_SIZE`: Size of each transfer chunk (default: 2MB)
- `$TMP_SIZE_LIMIT`: Maximum size of temporary storage per session (default: 50MB)

## License

This project is licensed under the GNU Lesser General Public License v2.1 (LGPL-2.1) - see the LICENSE file for details.

## Author

Denys Fedoryshchenko (nuclearcat AT nuclearcat.com)

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

For support, please open an issue in the project repository or contact the author. 