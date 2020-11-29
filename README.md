Cloud Storage Tools
===================

A suite of useful tools to access [Cloud Storage Server](https://github.com/cubiclesoft/cloud-storage-server) directly from the command-line.

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/) [![Discord](https://img.shields.io/discord/777282089980526602?label=chat&logo=discord)](https://cubiclesoft.com/product-support/github/)

Features
--------

* User profile management.
* Complete /files SDK and API coverage.  Manage, upload, and download files and folders.
* A complete, question/answer enabled command-line interface.  Nothing to compile.
* Has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your project.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Getting Started
---------------

The easiest way to get started is to play with the command-line interface.  The command-line interface is question/answer enabled, which means all you have to do is run:

````
php cs-tools.php
````

Once you grow tired of manually entering information, you can pass in some or all of the answers to the questions on the command-line:

````
# Create a profile called 'my-profile'
php cs-tools.php profiles create my-profile

# Upload a directory recursively to a '/photos' folder from a Windows machine.
php cs-tools.php files upload my-profile src=C:\photos dest=/photos diff=Y delete=Y

# Download the folder recursively to '/var/data/sync-photos' on a Mac/Linux machine.
php cs-tools.php files download my-profile src=/photos dest=/var/data/sync-photos diff=Y delete=Y
````

The -s option suppresses normal output (except for fatal error conditions), which allows for the processed JSON result to be the only thing that is output.
