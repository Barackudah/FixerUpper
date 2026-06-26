<!-- Advertising carousel mirrors the homepage logo strip. -->
<section class="advertising-block" aria-label="Advertising banners">
    <div class="ad-viewport">
        <div class="ad-track">
            <?php for ($adSet = 0; $adSet < 2; $adSet++): ?>
                <div class="ad-set">
                    <div class="ad-banner">
                        <a href="https://www.amd.com/" target="_blank" rel="noopener noreferrer" aria-label="AMD official website">
                            <img class="ad-logo-amd" src="assets/images/amd_logo.png" alt="AMD">
                        </a>
                    </div>
                    <div class="ad-banner">
                        <a href="https://www.apple.com/" target="_blank" rel="noopener noreferrer" aria-label="Apple official website">
                            <img class="ad-logo-compact ad-logo-apple" src="assets/images/apple_logo.png" alt="Apple">
                        </a>
                    </div>
                    <div class="ad-banner">
                        <a href="https://www.nvidia.com/" target="_blank" rel="noopener noreferrer" aria-label="NVIDIA official website">
                            <img src="assets/images/nvidia_logo.png" alt="NVIDIA">
                        </a>
                    </div>
                    <div class="ad-banner">
                        <a href="https://store.steampowered.com/" target="_blank" rel="noopener noreferrer" aria-label="Steam official website">
                            <img class="ad-logo-compact" src="assets/images/steam_logo.png" alt="Steam">
                        </a>
                    </div>
                    <div class="ad-banner">
                        <a href="https://www.intel.com/" target="_blank" rel="noopener noreferrer" aria-label="Intel official website">
                            <img src="assets/images/intel_logo.png" alt="Intel">
                        </a>
                    </div>
                    <div class="ad-banner">
                        <a href="https://www.ea.com/ea-app" target="_blank" rel="noopener noreferrer" aria-label="Origin EA app official website">
                            <img src="assets/images/origin_logo.png" alt="Origin">
                        </a>
                    </div>
                    <div class="ad-banner">
                        <a href="https://www.xbox.com/" target="_blank" rel="noopener noreferrer" aria-label="Xbox official website">
                            <img class="ad-logo-compact" src="assets/images/xbox_logo.png" alt="Xbox">
                        </a>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
