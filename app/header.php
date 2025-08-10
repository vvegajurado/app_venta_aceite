<style>
    .top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 20px;
        background-color: #fff;
        border-bottom: 1px solid #e0e0e0;
        margin-bottom: 20px;
    }
    .sidebar-toggle {
        font-size: 1.5rem;
        cursor: pointer;
        color: #333;
    }
</style>

<div class="top-bar">
    <i class="bi bi-list sidebar-toggle" id="sidebar-toggle"></i>
    <!-- You can add other header elements here, like user profile, notifications, etc. -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const toggleButton = document.getElementById('sidebar-toggle');
    const wrapper = document.querySelector('.wrapper');

    if (toggleButton && wrapper) {
        toggleButton.addEventListener('click', function() {
            wrapper.classList.toggle('sidebar-collapsed');
        });
    }
});
</script>
