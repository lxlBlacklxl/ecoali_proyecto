document.addEventListener("DOMContentLoaded", function() {
    // Target either .aside or .sidebar
    const menuElement = document.querySelector(".aside, .sidebar");
    if (!menuElement) return;

    // Create hamburger button
    const hamburger = document.createElement("button");
    hamburger.className = "admin-hamburger";
    hamburger.type = "button";
    hamburger.innerHTML = `
        <span class="bar"></span>
        <span class="bar"></span>
        <span class="bar"></span>
    `;
    
    // Insert hamburger into body
    document.body.appendChild(hamburger);

    // Create overlay
    const overlay = document.createElement("div");
    overlay.className = "admin-menu-overlay";
    document.body.appendChild(overlay);

    // Toggle menu
    function toggleMenu() {
        menuElement.classList.toggle("active");
        hamburger.classList.toggle("active");
        overlay.classList.toggle("active");
    }

    hamburger.addEventListener("click", toggleMenu);
    overlay.addEventListener("click", toggleMenu);

    // Auto-close menu when sidebar button/link is clicked
    menuElement.querySelectorAll("button, a").forEach(item => {
        item.addEventListener("click", () => {
            if (menuElement.classList.contains("active")) {
                toggleMenu();
            }
        });
    });
});
