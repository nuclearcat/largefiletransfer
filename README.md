# Large File Transfer

A secure, web-based solution for transferring large files between computers. This application allows users to send and receive files of any size through a simple web interface, with built-in security features and chunked transfer capabilities.

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

## Installation

1. Place the files in your web server directory
2. Ensure the web server has write permissions to the temporary directory
3. Access the application through your web browser
4. Set up the initial password when prompted

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