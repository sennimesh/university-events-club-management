# Vanni Campus Connect
### University Event and Club Management System

**Group 17 | IT2133(P) – Advanced Web Programming**  
**University of Vavuniya**

---

## 1. Project Overview

Vanni Campus Connect is a web-based University Event and Club Management System developed to simplify and organize university club activities, event management, student participation, and administrative operations.

The system provides a centralized platform where students can explore university clubs, discover upcoming events, register for events, and interact with university club activities. Club leaders can manage their clubs and activities, while administrators can oversee users, clubs, events, and system records.

The application is developed using PHP, MySQL, HTML, CSS, and JavaScript following the Model-View-Controller (MVC) architectural pattern.

## 2. Project Objectives

- Provide a centralized platform for university events and clubs.
- Simplify student registration and authentication.
- Allow students to discover and participate in university events.
- Support club management and membership administration.
- Provide event registration and attendance management.
- Enable administrators to manage users, clubs, events, and reports.
- Improve communication and coordination of university activities.

## 3. Main Features

### Student Features
- Student registration and login.
- User profile management.
- Browse available university clubs.
- View club information.
- Discover upcoming events.
- View event details.
- Register for events.
- Manage event participation.

### Club Management
- Create and manage club information.
- View club members.
- Manage membership requests.
- Organize club activities.
- Publish club announcements.

### Event Management
- Create and submit events.
- View upcoming and approved events.
- Display event details.
- Manage event registrations.
- Track event participation.
- Manage attendance and feedback.

### Administration
- Administrative dashboard.
- User management.
- Club management.
- Event approval and management.
- Event and participation records.
- Reports and system administration.

## 4. Technologies Used

| Technology | Purpose |
|---|---|
| PHP | Backend development |
| MySQL | Database management |
| HTML5 | Page structure |
| CSS3 | Styling and layouts |
| JavaScript | Client-side functionality |
| Bootstrap | Responsive UI components, where used |
| XAMPP | Local development environment |
| Apache | Web server |
| Git & GitHub | Version control and collaboration |
| VS Code | Code editing |

## 5. System Architecture

The application follows the Model-View-Controller (MVC) architecture.

### Model
Handles database operations and application data.

Examples:
- `User.php`
- `Club.php`
- `Event.php`

### View
Responsible for displaying the user interface.

Examples:
- Login and registration pages.
- Club pages.
- Event pages.
- Admin pages.

### Controller
Handles application requests and connects models with views.

Examples:
- `AuthController.php`
- `ClubController.php`
- `EventController.php`
- `AdminController.php`
- `SiteController.php`

## 6. Project Structure

```text
Vanni-Campus-Connect/
│
├── app/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── ClubController.php
│   │   ├── EventController.php
│   │   ├── SiteController.php
│   │   ├── LeaderController.php
│   │   └── AdminController.php
│   │
│   ├── Core/
│   │   ├── Database.php
│   │   ├── Helpers.php
│   │   ├── Router.php
│   │   ├── Reports.php
│   │   └── Schema.php
│   │
│   ├── Models/
│   │   ├── User.php
│   │   ├── Club.php
│   │   └── Event.php
│   │
│   └── Views/
│       ├── pages/
│       ├── partials/
│       └── ...
│
├── public/
│   └── assets/
│       ├── app.css
│       ├── app.js
│       └── mark.svg
│
├── database/
│   └── schema.sql
│
├── scripts/
│   ├── setup.php
│   ├── seed.php
│   └── add_sample_data.php
│
├── index.php
├── SETUP_WINDOWS.bat
├── ADD_MORE_SAMPLE_DATA_WINDOWS.bat
└── README.md
```

*Note: The structure above summarizes the main application directories and files. Refer to the repository for the complete source code.*

## 7. System Requirements

- Windows, Linux, or macOS.
- XAMPP or another compatible PHP and MySQL environment.
- PHP.
- MySQL or MariaDB.
- Apache Web Server.
- Modern web browser.

## 8. Installation and Setup

### Step 1: Clone the Repository

Open a terminal and execute:

```bash
git clone https://github.com/g-Kalpana0827/university-events-club-management.git
```

### Step 2: Move the Project

For XAMPP on Windows, place the project inside:

```text
C:\xampp\htdocs\
```

### Step 3: Start XAMPP

Open the XAMPP Control Panel and start:

- Apache
- MySQL

### Step 4: Configure the Database

1. Open phpMyAdmin:

   http://localhost/phpmyadmin

2. Create or select the database configured for the application.
3. Import the SQL file:

```text
database/schema.sql
```

4. Check the database connection settings in the application's database configuration.

### Step 5: Configure Database Connection

Check the database configuration used by:

```text
app/Core/Database.php
```

Set the correct database host, database name, username, and password for your local MySQL environment.

Do not commit private database credentials to GitHub.

### Step 6: Run the Application

Open the application using the appropriate local URL, depending on the project folder and configured document root.

Example:

```text
http://localhost/university-events-club-management/
```

If the application uses a separate public document root, configure Apache to serve that directory.

## 9. Database

The system uses MySQL to store and manage application information.

The database supports information related to:

- Students and user accounts.
- Clubs and memberships.
- Events and event participation.
- Attendance and feedback.
- Administrative records.

The database schema is provided in:

```text
database/schema.sql
```

## 10. Version Control and Collaboration

GitHub is used to manage source code and coordinate development among Group 17 members.

### Branching Strategy

| Branch | Purpose |
|---|---|
| `main` | Stable project version |
| `develop` | Integrated development version |
| `feature/*` | Individual member development branches |

### Development Workflow

1. Create a feature branch from `develop`.
2. Work on the assigned project components.
3. Commit and push changes to the feature branch.
4. Create a Pull Request targeting `develop`.
5. Review and test the changes.
6. Merge approved changes into `develop`.
7. Test the integrated application.
8. Merge the stable version into `main`.

All team members should use their own GitHub accounts and commit their actual contributions.

## 11. Testing

The application should be tested to verify:

- User registration and authentication.
- Database connectivity.
- Club management operations.
- Event creation and management.
- Event registration and cancellation.
- Attendance management.
- Administrative functions.
- Form validation.
- Responsive user interface.
- Integration between application modules.

## 12. Security Considerations

- Passwords should be securely hashed.
- Database queries should use prepared statements.
- User input should be validated and sanitized.
- Access to administrative functions should be restricted.
- Sessions should be managed securely.
- Sensitive configuration files and credentials should not be published.

## 13. Project Team

**Group 17**  
BSc in Information Technology  
University of Vavuniya

This project is developed collaboratively as part of the IT2133(P) – Advanced Web Programming course.

## 14. Academic Disclaimer

Vanni Campus Connect is an academic software development project created for educational purposes as part of the University of Vavuniya undergraduate coursework. It is not an official university information system.

## 15. License

This project is developed for academic and educational purposes. No separate open-source license is granted unless one is explicitly added to this repository.

---

**Vanni Campus Connect – Connecting Students, Clubs, and Events.**
