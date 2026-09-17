# Strategy to Eliminate UI Duplication in Kembara

## 1. Overview
The Kembara codebase has significant duplication of UI elements across almost all pages. This plan implements a modular layout system using PHP includes to centralize the HTML head, navigation, footer, and global scripts.

## 2. Proposed Architecture

### 2.1 `includes/layout_header.php`
This file handles the document start and the primary navigation structures.

**Content Structure:**
- `<!DOCTYPE html>`
- `<html lang="ms" data-theme="garden">`
- `<head>`:
    - Meta tags.
    - `<title>Kembara - <?= $pageTitle ?></title>`.
    - Theme initialization script (immediate execution to prevent flicker).
    - Google Fonts (Outfit).
    - daisyUI & Tailwind CSS CDN links.
    - Custom CSS (`assets/css/style.css`).
    - Echo `$extraCSS` if defined (for page-specific styles).
- `<body class="min-h-screen">`
- **Sidebar**: `aside#sidenav-main` with role-based links.
- **Mobile Dock**: Floating bottom navigation with the "Lagi" popover and role-based links.

### 2.2 `includes/top_nav.php`
A reusable component for the top navigation bar, included inside the `<main>` tag.

**Content Structure:**
- `<nav class="sticky top-0 ...">`
- **Page Title**: Displays `$pageTitle` and breadcrumbs.
- **Search Form**: Conditional rendering based on `$showSearch`. Uses `$searchAction` and `$searchPlaceholder`.
- **Theme Toggle**: The button and icon for light/dark mode.
- **Notification Dropdown**: Shows `$pendingApprovals` count and links to pending bookings.
- **User Profile Dropdown**: Displays `$fullname`, `$role`, `$email`, and `$profilePicture`.

### 2.3 `includes/layout_footer.php`
This file handles the document end and global client-side logic.

**Content Structure:**
- Global Footer: Copyright notice.
- **Common Scripts**:
    - Floating Nav Active logic: Handles `active` states for mobile dock and sidebar.
    - Theme Toggle logic: Manages `localStorage` and `data-theme` attribute.
    - Perfect Scrollbar and `makeClickable` utility.
- Echo `$extraJS` if defined (for page-specific scripts).
- Closing `</body>` and `</html>`.

## 3. Page Modification Strategy

Each page (`dashboard.php`, `bookings.php`, etc.) will be refactored as follows:

1.  **Variable Setup**:
    ```php
    $pageTitle = "Page Name";
    $showSearch = true;
    $searchAction = "page.php";
    $searchPlaceholder = "Search for...";
    // Other required user/role variables
    ```
2.  **Header Include**: `include 'includes/layout_header.php';`
3.  **Top Nav Include**:
    ```php
    <main class="xl:ml-64 relative min-h-screen pb-24 xl:pb-0">
        <?php include 'includes/top_nav.php'; ?>
    ```
4.  **Content**: The main page content inside a wrapper `div`.
5.  **Footer Include**:
    ```php
    </main>
    <?php include 'includes/layout_footer.php'; ?>
    ```

## 4. Handling Page-Specifics

- **CSS**: Pages with unique styles will define `$extraCSS = '<style>...</style>';` before including the header.
- **JS**: Pages with unique scripts (like Dashboard's ApexCharts) will keep their `<script>` blocks before the footer include.
- **Search**: The `top_nav.php` will handle different search forms (e.g., simple input vs. form with hidden fields) by checking `$showSearch` and the provided action/placeholder.

## 5. Implementation Steps

1.  **Create `includes/layout_header.php`**: Extract code from `dashboard.php` and `bookings.php`.
2.  **Create `includes/top_nav.php`**: Extract the nav bar and parameterize it.
3.  **Create `includes/layout_footer.php`**: Extract footer and common scripts.
4.  **Refactor Pages**: Apply the new structure to the following pages in order:
    - `dashboard.php`
    - `bookings.php`
    - `vehicles.php`
    - `drivers.php`
    - `users.php`
    - `profile.php`
    - `view-vehicle.php`
    - `view.php`
    - `activity_log.php`

## 6. Verification Plan

| Test Case | Expected Result |
| :--- | :--- |
| **Visual Consistency** | No layout shifts or missing elements across all refactored pages. |
| **Theme Switching** | Clicking the theme toggle updates the UI and persists on refresh. |
| **Navigation** | Sidebar and Mobile Dock links navigate to correct pages and show active state. |
| **Role Access** | "Pengguna" and "Log Aktiviti" links are only visible to authorized roles. |
| **Search Function** | Search forms submit to the correct action with the right placeholders. |
| **Notifications** | The notification badge correctly reflects pending approvals. |
| **Page Scripts** | ApexCharts on Dashboard still render and update on theme change. |
