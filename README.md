Sendy 3rd Party API Integration
===============================

##About
This project will allow you to send email quickly through API calls using services other than Amazon SES with Sendy.

## Installation Instructions
Copy these files into a **scheduling** folder in your Sendy root (or wherever you would like them to reside.)

Alternatively, run the following command on the command line in your Sendy root:
```
git clone https://github.com/BlaineMoore/sendy-api-mailer.git scheduling
```

Next, edit the **scheduling/includes/scheduling-config.php** file to update how to access the regular Sendy
**includes/config.php** file. Obviously, if you are not putting it in the **scheduling** folder under the root, you'll
need to access the scheduling config file from wherever you install it.

Inside of the scheduling config file, there will eventually be some values you can customize; if you are going to get
updates directly from GitHub, you may want to place those variables into your standard Sendy configuration file so that
you don't have to worry about overwriting them. It will work just fine either way. *At the moment, there are no
customizations necessary, as the only service supported just uses regular SMTP login credentials and does not require a
separate API.*

Once you have done that, then you can just point your mailing service's SMTP credentials into the normal place in the
settings page for that brand.

The final step is to update the cron job to point from **scheduled.php** to **scheduling/scheduled.php** instead.

For example, the *standard* cron job would look something like this:
```
php /home/username/public_html/scheduled.php > /dev/null 2>&1
```
Instead, it should look more like this:
```
php /home/username/public_html/scheduling/scheduled.php > /dev/null 2>&1
```

##Supported Services
Currently, I have implemented the following services:

* [CritSend](http://www.critsend.com) - uses standard smtp username/password in the settings page; no other customization required (sends in batches of 500 messages)

##Future Services
If you have any future services that you'd like to include, just let me know and point me to the documentation, or else
use any of the existing files as a template and push the update to this repository. I will be adding more.

