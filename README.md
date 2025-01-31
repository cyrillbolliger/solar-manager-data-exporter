# Solar Manager Data Exporter

You need the smart meter data from your Solar Manager in a CSV file? In higher
resolution than the Solar Manager app provides?

Here is a simple PHP script that fetches data from the Solar Manager API, stores
it in a SQLite database and provides a simple interface to export the data as 
CSV. It works best with 5 or 15 minute resolution, but 10 second resolution is
also possible.

The script can be deployed to a standard managed web hosting like 
[Cyon](https://www.cyon.ch/), [Infomaniak](https://www.infomaniak.com/) or
[Hostpoint](https://www.hostpoint.ch/).

## Installation

### Prerequisites

- PHP >= 8.3
- PHP extensions: `curl`, `pdo_sqlite`, `sqlite3`

### Deployment

On your web server:
1. Create a new directory in your web root (e.g. `/var/www/html/solar-manager`).
2. Create a subdirectory `public` inside the directory created in the previous
   step (e.g. `/var/www/html/solar-manager/public`).
3. Create a (sub-)domain that points to `/var/www/html/solar-manager/public`.
4. Get a TLS certificate for the domain.
5. **Protect the directory** `/var/www/html/solar-manager` with [basic auth](#basic-auth).
6. Force the use of HTTPS by [redirecting all HTTP requests to HTTPS](#force-https).
7. Configure your web server to use `PHP >= 8.3`.
8. Set up a [cron job](#cron-jobs) to fetch new data from the Solar Manager API.
9. Set up a [cron job](#cron-jobs) to check if new data is fetched regularly.

> [!WARNING]
> Failing to protect the directory with basic auth (step 6) will **expose your
> smart meter data to the public**. Test authentication by opening the domain in
> your browser in incognito mode.

On your local machine:

9. Clone this repository to your linux machine.
10. Copy `config.php.example` to `config.php` and adjust the settings. Use the
   same credentials as you use for the Solar Manager app.
11. Deploy the script to your web server: 
   ```bash
   # make the deploy script executable
   chmod +x deploy.sh
   # run the deploy script
   ./deploy.sh <ssh-user>@<your-server> <path-to-solar-manager-dir-on-web-root>
   # example: ./deploy.sh user@my-server /var/www/html/solar-manager
   ```

The script is now deployed to your web server and ready to use via CLI or in the
browser via the domain you created in step 3.

### Cron jobs

The following two cron jobs are recommended:

```bash
11 03 * * * /var/www/html/solar-manager/cli --update >/dev/null
51 03 * * * /var/www/html/solar-manager/alert.sh 176400 mail@example.com
```

The first cron job fetches new data from the Solar Manager API every day at 3:11 
am. The second cron job sends an email to `mail@example.com` if no new data was
fetched within the last 49 hours (`176400 seconds`). Feel free to adjust the 
intervals to your needs.

Some managed web hosting providers run cron jobs with a different PHP version.
In this case, you can provide the path to the PHP binary in the cron job:

```bash
11 03 * * * PATH=/path/to/php83/usr/bin/:$PATH /var/www/html/solar-manager/cli --update >/dev/null
51 03 * * * PATH=/path/to/php83/usr/bin/:$PATH /var/www/html/solar-manager/alert.sh 176400 mail@example.com
```

### Basic auth

If you use apache, you can protect the directory with basic auth by creating a
`.htaccess` file in `/var/www/html/solar-manager`:

```apache
AuthType Basic
AuthName "Please authenticate"
AuthUserFile /path/to/.htpasswd
Require valid-user
```

### Force HTTPS

To force the use of HTTPS, add the following lines to the 
`/var/www/html/solar-manager/.htaccess` file:

```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
```

Create the `.htpasswd` file with the following command:

```bash
htpasswd -c /path/to/.htpasswd username
```

## Usage

### CLI

Check the help message for available commands:

```bash
./cli help
```

### Web interface

A minimal but self-explanatory web interface is available at the domain you
created in step 3 of the installation. You can export the data as CSV files
there.

## Try out locally (Docker)

1. Clone this repository to your linux machine.
2. Copy `config.php.example` to `config.php` and adjust the settings. Use the
   same credentials as you use for the Solar Manager app.
3. Build and run the docker image:
   ```bash
   docker compose up -d
   ```
4. Open your browser at `http://localhost:8000` and log in with:
   - user: `admin`
   - password: `admin`

> [!WARNING]
> The docker image is not suitable for production use. It is only intended for
> testing and development purposes.

## Additional Notes

The script is designed to be as simple as possible (one file + config file).

To prevent hitting rate limits when exporting a lot of data, the data is fetched 
regularly (cron job) and stored in a local SQLite database 
(`./storage/db.sqlite`).

Errors are logged to `./storage/errors.log`.

Without providing any further arguments, `./cli --update` picks up where it left
off the last time. So it should automatically recover from temporary errors. The
`alert.sh` cron job sends an email if no new data was fetched within the defined
time frame. So you get notified about long-lasting errors. Check `./cli --logs`
for details.

The addition, removal or replacement of smart meters should be handled 
gracefully, but it wasn't tested in production yet. Please file an issue if you
encounter any problems.

## License

This project is licensed under the terms of the GNU Affero General Public 
License v3.0. You can find the full text of the license 
[here](https://www.gnu.org/licenses/agpl-3.0.en.html#license-text).