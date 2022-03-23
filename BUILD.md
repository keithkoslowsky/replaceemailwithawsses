# Building the plugin

### .env file
Create an .env file with the following. Update to reflect your world.

You will want to use either AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY or AWS_PROFILE. AWS_PROFILE is the profile found in ~/.aws/credentials. If you want to use an AWS_PROFILE, then comment out  AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY and uncomment AWS_PROFILE.

```
AWS_ACCESS_KEY_ID=XXX
AWS_SECRET_ACCESS_KEY=XXX
# AWS_PROFILE=foobar

REWAS_FROM_ADDRESS=foobar@example.com
REWAS_FROM_NAME="Foo Bar"
REWAS_REGION=us-east-1

# mysql db image (only needed for development of the plugin and not for the plugin itself)
MYSQL_DATABASE=exampledb
MYSQL_USER=exampleuser
MYSQL_PASSWORD=examplepass
MYSQL_RANDOM_ROOT_PASSWORD='1'

# wordpress image (only needed for development of the plugin and not for the plugin itself)
WORDPRESS_DB_HOST=db
WORDPRESS_DB_USER=exampleuser
WORDPRESS_DB_PASSWORD=examplepass
WORDPRESS_DB_NAME=exampledb
WORDPRESS_DEBUG=1
```

### Build Docker image
Building a Docker image is helpful to run tests (PHPUnit) or run Composer.

```
docker build -t replaceemailwithawsses -f docker/Dockerfile .
```

### Run Composer
```
docker run --env-file .env -it --entrypoint bash -v $PWD:$PWD -w $PWD replaceemailwithawsses
composer install
composer dump-autoload -o
```

### Run webserver
```
(cd docker; docker-compose up --remove-orphans --build)
```
Now visit [http://localhost:8080](http://localhost:8080) for your site and [http://localhost:8080/wp-admin](http://localhost:8080/wp-admin) to log into WordPress.

<!-- ## Run tests - [TODO]
```
docker run -e ABSPATH=/ --env-file .env -it --entrypoint bash -v $PWD:$PWD -w $PWD replaceemailwithawsses
./vendor/bin/phpunit tests/
```
-->

### Compress with zip, to submit to WordPress Plugins
```
WORKING_DIR=`pwd`; rm -rf /tmp/replaceemailwithawsses; mkdir -p /tmp/replaceemailwithawsses; cp -r src/ReplaceEmailWithAwsSes.php vendor /tmp/replaceemailwithawsses; mkdir -p versions; VERSION=$(sed -n -e 's/* @version //p' src/ReplaceEmailWithAwsSes.php | sed 's/ //'); (cd /tmp/replaceemailwithawsses; zip -r ${WORKING_DIR}/versions/replace-email-with-aws-ses-${VERSION}.zip *); rm -rf /tmp/replaceemailwithawsses;
```
