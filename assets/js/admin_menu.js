document.addEventListener("DOMContentLoaded", function() {
    // Only run if .aside exists
    const aside = document.querySelector(".aside");
    if (!aside) return;

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
        aside.classList.toggle("active");
        hamburger.classList.toggle("active");
        overlay.classList.toggle("active");
    }

    hamburger.addEventListener("click", toggleMenu);
    overlay.addEventListener("click", toggleMenu);
});
