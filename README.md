<div align="center">

# JobIT

### A Local Short-Gig Marketplace with Score-Based Matching of Clients and Workers

*"May Gawa? May Gagawa!"*

[![Live Demo](https://img.shields.io/badge/Live_Demo-tongol--danaxel.infy.click-4154f1?style=for-the-badge)](https://tongol-danaxel.infy.click)

![PHP](https://img.shields.io/badge/PHP_8.1-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-F7DF1E?style=flat-square&logo=javascript&logoColor=black)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=flat-square&logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?style=flat-square&logo=css3&logoColor=white)

**CITE 004 – Data Structures and Algorithms** <br>
Technological Institute of the Philippines – Manila · First Semester, SY 2026–2027

</div>

---

## Overview

**JobIT** is a client-to-client web marketplace for **local short gigs and side hustles** in the Philippines, such as repairs, catering, cleaning, tutoring and deliveries.

- **One account, two roles:** any user can post gigs as a **client** and apply to other people's gigs as a **worker**.
- **No resume or certificates required:** profiles list skills, location and availability, keeping the platform open to students, freelancers and informal workers.
- **Transparent matching:** every worker–gig pair receives a **0–100 compatibility score** with a visible breakdown, so both sides can see *why* a result ranks where it does.

JobIT is the platform in the middle. It helps clients find the right people quickly; the final decision always stays with the client.

---

## Key Features

| Module | Description |
|---|---|
| **Home** | Personalized greeting, the user's #1 match, and top-3 recommendations |
| **Browse** | Keyword search; filters by category, work type and saved listings; sort by best match, newest, or highest budget |
| **Why this score?** | Points breakdown (skills, location, availability) with matched and missing skills |
| **Weak skill match** | Warning when a high score comes mostly from location and schedule rather than skills |
| **Saved listings** | Heart button to bookmark gigs for later |
| **Post a Listing** | Create and edit gigs with category, work type, budget, location, schedule and required skills |
| **My Listings** | Manage gigs; review applicants in **Pending / Shortlisted / Rejected** tabs |
| **Matches** | Recommended gigs, applicant queue, and **My Applications** status tracking |
| **Shortlist & Email** | Shortlisting prepares an email draft the client sends from Gmail or their mail app |
| **Admin Panel** | Separate admin login, platform statistics, search, filters and pagination, account and listing moderation |
| **Benchmark** | Live comparison of merge sort, bubble sort and min-heap top-K |

---

## Compatibility Score

| Criterion | Points | Rule |
|---|:---:|---|
| **Skills** | 50 | (required skills the worker has ÷ required skills) × 50 |
| **Location** | 30 | Same city = 30 · Different city = 15 · Missing = 0 |
| **Availability** | 20 | (required time slots the worker selected ÷ required slots) × 20 |

**Example:** 3 of 5 skills (30) + different city (15) + 2 of 3 slots (13.33) = **58.33%**

**Why these weights?** Skills decide whether the work can be done at all, location decides whether a local, in-person gig is reachable, and schedules are usually the easiest to adjust. Skill and city nicknames are normalized before comparison (`JS` → JavaScript, `QC` → Quezon City).

The score is a **guide for deciding whom to contact first**. It does not verify qualifications.

---

## Data Structures & Algorithms

All ranking uses **self-implemented algorithms**. PHP's `sort()` / `usort()` and SQL `ORDER BY` are never used for ranking.

| Concept | Where | Purpose | Complexity |
|---|---|---|:---:|
| **Merge sort** (stable, divide and conquer) | `sorting.php` → Browse, Matches, My Listings, Admin | Ranks gigs and applicants | O(n log n) |
| **Bubble sort** | `benchmark.php` | Baseline for performance comparison | O(n²) |
| **Min-heap (top-K)** | `sorting.php` → Home | Selects the top 3 without a full sort | O(n log k) |
| **Hash set** | `browse.php`, `matches.php`, `db.php` | Saved and applied lookups, skill matching | O(1) per lookup |
| **Hash map** | `db.php` | Skill and city synonym normalization | O(1) per lookup |
| **Weighted scoring** | `db.php` → `getScoreBreakdown()` | Single source of truth for every score | O(s) per pair |

> On 5,000 random scores, the min-heap needed about **5,000 comparisons** to find the top 3, versus about **55,000** for a full merge sort. See the Benchmark page.

---

## Tech Stack

- **Backend:** PHP 8.1 (PDO, prepared statements, bcrypt password hashing)
- **Database:** MySQL
- **Frontend:** HTML5, CSS3, vanilla JavaScript (AJAX page updates without reloads)
- **Hosting:** InfinityFree (live) · XAMPP (local development)

---

## Project Structure

```
├── index.php             # Landing page
├── auth.php              # Login and sign-up
├── dashboard.php         # Home (top matches via min-heap)
├── browse.php            # Search, filters, sorting, details panel
├── post-listing.php      # Create and edit listings
├── my-listings.php       # Manage listings and applicants
├── matches.php           # Recommendations, applicant queue, My Applications
├── listing-details.php   # Single listing view
├── profile.php           # User profile
├── reset-password.php    # Password reset
├── admin-login.php       # Admin authentication
├── admin.php             # Admin panel
├── benchmark.php         # Sorting algorithm benchmark
├── sorting.php           # Merge sort, bubble sort, min-heap top-K
├── db.php                # Database connection and scoring engine
├── style.css / script.js
└── *.png / *.jpg         # Images
```

---

## Running Locally

1. Install **[XAMPP](https://www.apachefriends.org/)** and start **Apache** and **MySQL**.
2. Clone this repository into `C:\xampp\htdocs\DSAJOBIT`.
3. Open **phpMyAdmin**, create a database named `jobit_db`, and create the tables.
4. Visit **http://localhost/DSAJOBIT**.

Production database credentials live in a server-only `config.live.php`, which is excluded from this repository.

---

## Limitations

- Skills are **self-declared and not verified**; clients confirm fit by talking to the worker.
- Years of experience are **not scored** in this version.
- Location matching is **city-level** only.
- JobIT is built for **informal local gigs**, not corporate recruitment.

## Future Work

Ratings and reviews · worker expected rates · adjustable score weights · experience as a scoring factor · notifications · in-app messaging

---

## Team

| Name | Role |
|---|---|
| **Dan Axel M. Tongol** | Project Leader · Front End / Back End |
| **Hope B. Calunsod** | Documentation · Back End |
| **Carlos John L. Rama** | Documentation · Back End |

<div align="center">

*Developed for academic purposes – CITE 004, Technological Institute of the Philippines – Manila*

</div>
