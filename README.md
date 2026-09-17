QUIQQER Management System
========

![QUIQQER](bin/images/Readme.png)

Copyright PCSG OhG - www.pcsg.de - www.quiqqer.com

About QUIQQER
--------

![Static Badge](https://img.shields.io/badge/PHP-^8.1-purple)
![Static Badge](https://img.shields.io/badge/PHP-PSR-purple)
![Static Badge](https://img.shields.io/badge/MySQL-8.*-blue)
![Static Badge](https://img.shields.io/badge/PostgreSQL-12%2B-blue)
![Static Badge](https://img.shields.io/badge/SQLite-supported-blue)
![Static Badge](https://img.shields.io/badge/Webserver-Apache-white)
![Static Badge](https://img.shields.io/badge/Webserver-NGINX-white)

![Static Badge](https://img.shields.io/badge/Browser-Chrome-yellow)
![Static Badge](https://img.shields.io/badge/Browser-Safari-blue)
![Static Badge](https://img.shields.io/badge/Browser-Firefox-orange)
![Static Badge](https://img.shields.io/badge/Browser-Edge-blue)
![Static Badge](https://img.shields.io/badge/Browser-Opera-red)
![Static Badge](https://img.shields.io/badge/Browser-Brave-orange)

# What is QUIQQER?

**QUIQQER is a PHP framework designed for all kinds of web projects.**

QUIQQER has been continuously developed for over 15 years with the aim of creating a system that allows for the rapid
implementation of SaaS (Software as a Service) solutions. While it originally began as a CMS (Content Management
System), it has evolved into a highly versatile framework with more than 100 modules.

In 2024, we initiated a comprehensive restructuring, which will be completed in 2025. Going forward, QUIQQER will offer
a flexible module API and a robust administration system for users, rights, and groups.

### Create customized solutions based on your needs:

- **Want to create a blog?** Use QUIQQER + CMS + Blog Module
- **Need an e-commerce store?** Use QUIQQER + ecoyn
- **Looking for an ERP system?** Use QUIQQER + various ERP modules
- **Building a SaaS solution?** Use QUIQQER + Orders + Invoices + Memberships + Plans

Over 100 modules are already available and widely used . As the setup can sometimes be complex, we plan to release 
tutorials and videos in 2025 to help you get started.

As strong supporters of open source, the core of QUIQQER is free and open source. Whether you're looking to build a
SaaS, ERP, website, blog, ordering system, store, or MVP, QUIQQER has the tools to support you.

---

*QUIQQER is developed by PCSG - Computer & Internet Service OHG, a modern IT agency located in Solingen, Germany.*

### Features

- Template System with Layouts: QUIQQER offers a flexible template system with layouts, allowing you to create visually
  appealing and consistent designs for your web pages and web apps.

- Modular Based Management System: QUIQQER is built on a modular architecture, enabling easy management and
  customization of various components to suit your specific project requirements.

- Internal Package Management via Composer: QUIQQER integrates an internal package management system based on Composer.
  It also provides a bridge to npm and bower, giving you access to a vast number of packages for extended functionality.

- Easy XML Module API: QUIQQER offers a robust XML module API that allows you to easily build modules and plugins. With
  this API, you can define new page types, introduce custom user attributes, set up event reactions, extend user and
  group functionalities, and much more, providing extensive customization options for your projects.

- Event-Based System: QUIQQER employs an event-based system, enabling you to respond to and handle events within your
  applications effectively.

- Expandable with Lots of Modules: QUIQQER provides a wide range of modules that can be easily integrated into your
  projects. These modules cover various functionalities, allowing you to extend the capabilities of your website
  effortlessly. [Visit the QUIQQER Store](https://store.quiqqer.com/) to explore and download additional modules.

- Open Source and Free: The main source code of QUIQQER is completely open source and freely available, empowering you
  to modify and adapt it according to your specific needs.

- All-Important Modules for Professional Presence: QUIQQER includes essential modules that are crucial for creating a
  professional online presence or web project. These modules encompass features such as a flexible component template
  system (Bricks), a cron system, various controls (components), an email system with an email queue,
  user/group/permission management, support for multiple domains and languages, and the ability to create multiple
  projects.

And Many More: QUIQQER encompasses a wide range of additional features and possibilities that are designed to empower
you in creating and managing your web projects effectively.

**If you want to know more about what QUIQQER can do, visit our Discover page:**

- https://www.quiqqer.com/Discover

Getting Started
------

To get started with QUIQQER, follow the [official instructions](https://www.quiqqer.com/Start-now).

- [Installation via CLI / Server](https://www.quiqqer.com/Start-now/Installation#terminal-setup)
- [Installation via Web / Browser](https://www.quiqqer.com/Start-now/Installation#websetup)

### SQLite database

SQLite can be selected with the `sqlite`, `sqlite3` or `pdo_sqlite` database driver. Its default database file is
`etc/database/quiqqer.sqlite`. A custom relative path is resolved from the QUIQQER installation directory; an absolute
path can also be configured.

```ini
[db]
driver = "sqlite"
path = "etc/database/quiqqer.sqlite"
foreign_keys = 1
busy_timeout = 5000
journal_mode = "WAL"
synchronous = "NORMAL"
```

SQLite deployments must use a local filesystem shared by all PHP processes on the same host. The database file and its
parent directory must be writable by the PHP and console user. In-memory databases are intended for tests only.

Additional Resources
------

Expand your access to the QUIQQER community and find additional resources to get help and information:

- [Main Website](https://www.quiqqer.com/): Visit the official website for comprehensive resources, documentation, and
  updates.
- [Documentation](https://www.quiqqer.com/docs/): Read the guides and XML configuration reference. XML examples and schemas
  are available in the [documentation repository](https://dev.quiqqer.com/quiqqer/ecosystem/documentation/-/tree/next-2.x/xml).
- [Twitter: Main Developer Account](https://twitter.com/de_henne)
- [Gitlab](https://dev.quiqqer.com/quiqqer/core): Access QUIQQER's GitLab page for discussions, issue reporting, and
  contributions.
- [Discord](https://discord.gg/tjWg4ZE): Join the QUIQQER Discord server for real-time support and interactions with the
  community.
- [Facebook](https://www.facebook.com/Quiqqer) - German

Utilize these additional resources to engage with the QUIQQER community, ask questions, share experiences, and
collaborate with fellow users and developers.


Contribute & Support
----------

You would like to help but don't know how or where? We have written a Contribution Guide especially for you. Here are
all the steps in how and where you can help us. We are looking forward to you.

To the Contribution Guide:

- [English](CONTRIBUTING.md)
- [German](CONTRIBUTING-de.md)

We use our own Gitlab instance, if you find bugs or miss features, please write us there. Github serves as a mirror and
backup.

- [Issues](https://dev.quiqqer.com/quiqqer/core/-/issues)
- [Milestones](https://dev.quiqqer.com/quiqqer/core/-/milestones)

License
-------

- GPL-3.0+

Thank you
--------


QUIQQER owes its existence and success to the dedicated efforts of numerous developers and projects. We would like to
take this opportunity to thank all the open source projects on which QUIQQER is based. This starts with Linux and ends
with various JavaScript libraries.

We hope that with QUIQQER we make the open source area a little better.

Henning and Moritz from PCSG & QUIQQER
