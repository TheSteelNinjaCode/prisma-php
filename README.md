# Prisma PHP: The Next-Gen Framework Merging PHP’s Power with Prisma's ORM Mastery

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/php-8.2%2B-8892BF.svg)

PP (Prisma PHP) is a modern framework that fuses PHP’s robust server capabilities with the intuitive power of Prisma-style ORM. Designed for developers who value simplicity, speed, and modern workflows.

---

## ✨ Features

- ✅ PSR-4 autoloading
- ✅ Built on PHP 8.2+ for type safety & modern syntax
- ✅ Designed for reactive apps & Prisma-style ORM patterns
- ✅ Tiny footprint, blazing fast

---

## 🚀 Installation

Install via Composer:

```bash
composer require tsnc/prisma-php
```

PP uses PSR-4, so your classes load automatically. No manual includes needed.

---

## 🏁 Getting Started

Create and use a set:

```php
<?php

use PP\Set;

$set = new Set();
$set->add("Hello");
$set->add("World");

print_r($set->values());
```

---

## 🛠 Example Usage

```php
<?php

use PP\Set;

$set = new Set();
$set->add("PP");
$set->add("rocks");

var_dump($set->values());
```

---

## 📜 License

Released under the MIT License.

## ❤️ Credits

Created & maintained by Jefferson [The Steel Ninja Code](https://thesteelninjacode.com/).
