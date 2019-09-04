# getjoomla.php
A php script to prepare installation of selected Joomla! release.

# Instructions - Using FTP client
- Download `index.php` file from `https://raw.githubusercontent.com/bestproject/getjoomla.php/master/index.php`
- Place it with FTP client to the root directory of your domain.
- Open your domain in browser.
- Select release (latest version is selected by default)
- Click on `Install`
 
# Instructions - Using SSH client
Installing Joomla! on a VPS or dedicated server running Linux system.

- Run in a console `sudo -u WEBSITE_USER wget https://raw.githubusercontent.com/bestproject/getjoomla.php/master/index.php` in a directory where you wan't your Joomla! to be installed (where WEBSITE_USER is system user that will be running your website)
- Open your domain in browser.
- Select release (latest version is selected by default)
- Click on `Install`

 
# How it works
Script downloads selected release, unpacks, creates `.htaccess` file, removes itself and redirects you to installation process.
