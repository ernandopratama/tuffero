<script setup>
import { onMounted } from 'vue';
import { loadLegacyScripts } from '../legacy';

onMounted(loadLegacyScripts);
</script>

<template>
<div>

    <!-- Preloader Start -->
	<div class="preloader">
		<div class="loading-container">
			<div class="loading"></div>
			<div id="loading-icon"><img src="/images/loader.svg" alt=""></div>
		</div>
	</div>
	<!-- Preloader End -->

    <!-- Header Start -->
	<header class="main-header bg-section">
		<div class="header-sticky">
			<nav class="navbar navbar-expand-lg">
				<div class="container-fluid">
					<!-- Logo Start -->
					<a class="navbar-brand" href="/">
						<img src="/images/logo.svg" alt="Logo">
					</a>
					<!-- Logo End -->

					<!-- Main Menu Start -->
					<div class="collapse navbar-collapse main-menu">
                        <div class="nav-menu-wrapper">
                            <ul class="navbar-nav mr-auto" id="menu">
                                <li class="nav-item submenu"><a class="nav-link" href="/">Home</a>
                                    <ul>
                                        <li class="nav-item"><a class="nav-link" href="#">Home - Main</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Home - Image</a></li>
                                        <li class="nav-item"><a class="nav-link" href="/">Home - Video</a></li>
                                    </ul>
                                </li>                                
                                <li class="nav-item"><a class="nav-link" href="#">About Us</a></li>
                                <li class="nav-item"><a class="nav-link" href="#">Services</a></li>
                                <li class="nav-item"><a class="nav-link" href="#">Blog</a></li>
                                <li class="nav-item submenu"><a class="nav-link" href="#">Pages</a>
                                    <ul>                                        
                                        <li class="nav-item"><a class="nav-link" href="#">Service Details</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Blog Details</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Our Products</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Product Details</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Our Team</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Team Details</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Testimonials</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Image Gallery</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">Video Gallery</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">FAQs</a></li>
                                        <li class="nav-item"><a class="nav-link" href="#">404</a></li>
                                    </ul>
                                </li>
                                <li class="nav-item"><a class="nav-link" href="#">Contact Us</a></li>
                            </ul>
                        </div>

                        <!-- Header Social Links Start -->
                        <div class="header-social-links ">
                            <ul>
                                <li><a href="#"><i class="fa-brands fa-dribbble"></i></a></li>
                                <li><a href="#"><i class="fa-brands fa-instagram"></i></a></li>
                                <li><a href="#"><i class="fa-brands fa-facebook-f"></i></a></li>
                            </ul>
                        </div>
                        <!-- Header Social Links End -->
                        
                        <!-- Header Btn Start -->
                        <div class="header-btn">
                            <a href="#" class="btn-default">Shop Now</a>
                        </div>
                        <!-- Header Btn End -->                      
					</div>
					<!-- Main Menu End -->
					<div class="navbar-toggle"></div>
				</div>
			</nav>
			<div class="responsive-menu"></div>
		</div>
	</header>
	<!-- Header End -->

    <!-- Hero Section Start -->
    <div class="hero hero-bg-image hero-video bg-section">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-lg-12">
                    <!-- Hero Box Start -->
                    <div class="hero-box">
                        <!-- Video Start -->
                        <div class="hero-bg-video">
                            <!-- Selfhosted Video Start -->
                            <!-- <video autoplay muted loop id="myvideo"><source src="/images/hero-bg-video.mp4" type="video/mp4"></video> -->
                            <video autoplay muted loop id="myvideo"><source src="https://demo.awaikenthemes.com/assets/videos/backeri-video.mp4" type="video/mp4"></video>
                            
                            <!-- Selfhosted Video End -->

                            <!-- Youtube Video Start -->
                            <!-- <div id="herovideo" class="player" data-property="{videoURL:'OjTRVpgtcG4',containment:'.hero-video', showControls:false, autoPlay:true, loop:true, vol:0, mute:false, startAt:0,  stopAt:296, opacity:1, addRaster:true, quality:'large', optimizeDisplay:true}"></div> -->
                            <!-- Youtube Video End -->
                        </div>
                        <!-- Video End -->
                        <!-- Hero Content Start -->
                        <div class="hero-content dark-section">
                            <!-- Section Title Start -->
                            <div class="section-title">
                                <h3 class="wow fadeInUp">Welcome to your neighborhood bakery</h3>
                                <h1 class="text-anime-style-2" data-cursor="-opaque">Handcrafted pastries, breads and cakes made <span>daily with love</span></h1>
                                <p class="wow fadeInUp" data-wow-delay="0.2s">We're here to flip the scripts on traditional baking. Think bold flavor combos, Insta-worthy pastries, and a rotating menu.</p>
                            </div>
                            <!-- Section Title End -->

                            <!-- Hero Body Start -->
                            <div class="hero-body wow fadeInUp" data-wow-delay="0.4s">
                                <!-- Hero Button Start -->
                                <div class="hero-btn">
                                    <a href="#" class="btn-default btn-highlighted">Shop Now</a>
                                </div>
                                <!-- Hero Button End -->
                                
                                <!-- Video Play Button Start -->
                                <div class="video-play-button border-btn">
                                    <p>Watch video</p>
                                    <a href="https://www.youtube.com/watch?v=Y-x0efG1seA" class="popup-video" data-cursor-text="Play">
                                        <i class="fa-solid fa-play"></i>
                                    </a>
                                </div>
                                <!-- Video Play Button End -->
                            </div>
                            <!-- Hero Body End -->

                            <!-- Hero Content List Start -->
                            <div class="hero-content-list wow fadeInUp" data-wow-delay="0.6s">
                                <ul>
                                    <li>Freshly Baked</li>
                                    <li>Cookies & Bars</li>
                                    <li>Seasonal Treats</li>
                                </ul>
                            </div>
                            <!-- Hero Content List End -->
                        </div>
                        <!-- Hero Content End -->
                    </div>
                    <!-- Hero Box End -->
                
                    <!-- Hero Info List Start -->
                    <div class="hero-info-list">
                        <!-- Hero Info Video Box Start -->
                        <div class="hero-info-video-box wow fadeInUp">
                            <!-- Hero Info Video Bg Image Start -->
                            <div class="hero-info-bg-image">
                                <figure>
                                    <img src="/images/hero-info-bg-image-1.jpg" alt="">
                                </figure>
                            </div>
                            <!-- Hero Info Video Bg Image End -->

                            <!-- Hero Video tag Button Start -->
                            <div class="hero-video-tag-btn">
                                <!-- Hero Video tag Start -->
                                <div class="hero-video-tag">
                                    <h3><a href="https://www.youtube.com/watch?v=Y-x0efG1seA" class="popup-video" data-cursor-text="Play">View Videos</a></h3>
                                </div>
                                <!-- Hero Video tag End -->
                                
                                <!-- Hero Video Button Start -->
                                <div class="hero-video-btn">
                                    <a href="#">
                                        <img src="/images/arrow-accent.svg" alt="">
                                    </a>
                                </div>
                                <!-- Hero Video Button End -->
                            </div>
                            <!-- Hero Video tag Button End -->

                            <!-- Hero Info Video Content Start -->
                            <div class="hero-info-video-content">
                                <h3>Classic Butter Croissant</h3>
                            </div>
                            <!-- Hero Info Video Content End -->
                        </div>
                        <!-- Hero Info Video Box End -->

                        <!-- Hero Image Rating Box Start -->
                        <div class="hero-image-rating-box wow fadeInUp" data-wow-delay="0.2s">
                            <!-- Hero Image Bg Image Start -->
                            <div class="hero-info-bg-image">
                                <figure>
                                    <img src="/images/hero-info-bg-image-1.jpg" alt="">
                                </figure>
                            </div>
                            <!-- Hero Image Bg Image End -->

                            <!-- Hero Rating Content Start -->
                            <div class="hero-rating-content">
                                <h3>“The best croissants I've ever tasted! Fresh, flaky & buttery - I can't start my mornings ”</h3>
                            </div>
                            <!-- Hero Rating Content End -->
                            
                            <!-- Google Rating Box Start -->
                            <div class="google-rating-box">
                                <!-- Google Rating Header Start -->
                                <div class="google-rating-header">
                                    <div class="icon-box">
                                        <img src="/images/icon-google.svg" alt="">
                                    </div>
                                    <div class="google-rating-content">
                                        <p>Google Rating</p>
                                        <p><i class="fa-solid fa-star"></i>
                                            <i class="fa-solid fa-star"></i>
                                            <i class="fa-solid fa-star"></i>
                                            <i class="fa-solid fa-star"></i>
                                            <i class="fa-solid fa-star"></i>
                                        </p>
                                    </div>
                                </div>
                                <!-- Google Rating Header End -->

                                <!-- Review Images Start -->
                                <div class="review-images">
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-1.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-2.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-3.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-4.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image add-more">
                                        <h3><span class="counter">5</span>K</h3>
                                    </div>
                                </div>
                                <!-- Review Images End -->
                            </div>
                            <!-- Google Rating Box End -->
                        </div>
                        <!-- Hero Image Rating Box End -->

                        <!-- Working Hours Item Start -->
                        <div class="working-hours-item wow fadeInUp" data-wow-delay="0.4s">
                            <!-- Working Hours Header Start -->
                            <div class="working-hours-header">
                                <h3>Working Hours</h3>
                                <img src="/images/icon-clock.svg" alt="">
                            </div>
                            <!-- Working Hours Header End -->
                            
                            <!-- Working Hours Body Start -->
                            <div class="working-hours-body">
                                <ul>
                                    <li>Monday - Friday <span>8:00 AM - 8:00 PM</span></li>
                                    <li>Saturday <span>9:00 AM - 6:00 PM</span></li>
                                    <li>Sunday <span>Closed</span></li>
                                </ul>
                            </div>
                            <!-- Working Hours Body End -->
                            
                            <!-- Working Hours Button Start -->
                            <div class="working-hours-btn">
                                <a href="#" class="btn-default">Get Started Now!</a>
                            </div>
                            <!-- Working Hours Button End -->
                        </div>
                        <!-- Working Hours Item End -->
                    </div>
                    <!-- Hero Info List End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Hero Section End -->
    
    <!-- About Us Section Start -->
    <div class="about-us bg-section">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <!-- About Us Content Start -->
                    <div class="about-us-content">
                        <!-- Section Title Start -->
                        <div class="section-title">
                            <h3 class="wow fadeInUp">About Us</h3>
                            <h2 class="text-anime-style-2" data-cursor="-opaque">Baking with heart, heritage, and a whole <span>lot of butter</span></h2>
                            <p class="wow fadeInUp" data-wow-delay="0.2s">At our bakery, every recipe tells a story - rooted in tradition perfected with passion, and baked with love. We blend time-honored techniques with high-quality ingredients.</p>
                            <p class="wow fadeInUp" data-wow-delay="0.4s">We're here to flip the script on traditional baking. Think bold flavor combos, Insta-worthy pastries, and a rotating menu.</p>
                        </div>
                        <!-- Section Title End -->

                        <!-- About Us List Start -->
                        <div class="about-us-list wow fadeInUp" data-wow-delay="0.6s">
                            <ul>
                                <li>Freshly Baked with Care.</li>
                                <li>100% Quality You Can Taste!</li>
                            </ul>
                        </div>
                        <!-- About Us list End -->

                        <!-- About Us Button Start -->
                        <div class="about-us-btn wow fadeInUp" data-wow-delay="0.8s">
                            <a href="#" class="btn-default">More About Us</a>
                        </div>
                        <!-- About Us Button End -->
                    </div>
                    <!-- About Us Content End -->
                </div>

                <div class="col-lg-6">
                    <!-- About Us Images Start -->
                    <div class="about-us-images">
                        <!-- About Image Start -->
                        <div class="about-image">
                            <figure class="image-anime-reveal">
                                <img src="/images/about-us-image-1.jpg" alt="">
                            </figure>
                        </div>
                        <!-- About Image End -->
                        
                        <!-- About Image Start -->
                        <div class="about-image">
                            <figure class="image-anime-reveal">
                                <img src="/images/about-us-image-2.jpg" alt="">
                            </figure>
                        </div>
                        <!-- About Image End -->

                        <!-- Year Experience Box Start -->
                        <div class="year-experience-circle">
                            <img src="/images/year-experience-circle.svg" alt="">
                            <h2><span class="counter">25</span>+</h2>
                        </div>
                        <!-- Year Experience Box End -->
                    </div>
                    <!-- About Us Images End -->
                </div>

                <div class="col-lg-12">
                    <!-- About Us Item List Start -->
                    <div class="about-us-item-list">
                        <!-- About Us Item Start -->
                        <div class="about-us-item wow fadeInUp">
                            <div class="icon-box">
                                <img src="/images/icon-about-us-item-1.svg" alt="">
                            </div>
                            <div class="about-us-item-content">
                                <h3>Wide Variety of Baked Goods</h3>
                                <p>From breads to cakes</p>
                            </div>
                        </div>
                        <!-- About Us Item End -->
                        
                        <!-- About Us Item Start -->
                        <div class="about-us-item wow fadeInUp" data-wow-delay="0.2s">
                            <div class="icon-box">
                                <img src="/images/icon-about-us-item-2.svg" alt="">
                            </div>
                            <div class="about-us-item-content">
                                <h3>Locally Sourced Ingredients</h3>
                                <p>Supporting local farmers</p>
                            </div>
                        </div>
                        <!-- About Us Item End -->
                        
                        <!-- About Us Item Start -->
                        <div class="about-us-item wow fadeInUp" data-wow-delay="0.4s">
                            <div class="icon-box">
                                <img src="/images/icon-about-us-item-3.svg" alt="">
                            </div>
                            <div class="about-us-item-content">
                                <h3>Custom Cakes & Orders</h3>
                                <p>Personalized cakes and desserts</p>
                            </div>
                        </div>
                        <!-- About Us Item End -->
                    </div>
                    <!-- About Us Item List End -->
                </div>
            </div>
        </div>
    </div>
    <!-- About Us Section End -->

    <!-- Our Services Section Start -->
    <div class="our-services bg-section">
        <div class="container">
            <div class="row section-row align-items-center">
                <div class="col-lg-6">
                    <!-- Section Title Start -->
                    <div class="section-title">
                        <h3 class="wow fadeInUp">Our services</h3>
                        <h2 class="text-anime-style-2" data-cursor="-opaque">Delicious service that bring joy to <span>every table</span></h2>
                    </div>
                    <!-- Section Title End -->
                </div>
                
                <div class="col-lg-6">
                    <!-- Section Title Content Start -->
                    <div class="section-title-content wow fadeInUp" data-wow-delay="0.2s">
                        <p>We believe every meal should be a celebration - that's why our artisanal baked goods are made with the finest ingredients and a whole lot of love, delivering joy and flavor to every table we serve.</p>
                    </div>
                    <!-- Section Title Content End -->
                </div>
            </div>

            <div class="row service-item-list">
                <div class="col-lg-3 col-md-6">
                    <!-- Service Item Start -->
                    <div class="service-item active wow fadeInUp">
                        <!-- Service Content Start -->
                        <div class="service-item-content">
                            <h3>01.</h3>
                            <h2><a href="#">Custom Cake Order</a></h2>
                            <p>Fresh, handcrafted bakery items delivered daily.</p>
                        </div>
                        <!-- Service Content End -->
                        
                        <!-- Service Readmore Start -->
                        <div class="service-readmore-btn">
                            <a href="#" class="readmore-btn">read more</a>
                        </div>
                        <!-- Service Readmore End -->
                    </div>
                    <!-- Service Item End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Service Item Start -->
                    <div class="service-item wow fadeInUp" data-wow-delay="0.2s">
                        <!-- Service Content Start -->
                        <div class="service-item-content">
                            <h3>02.</h3>
                            <h2><a href="#">Dessert Catering</a></h2>
                            <p>Fresh, handcrafted bakery items delivered daily.</p>
                        </div>
                        <!-- Service Content End -->
                        
                        <!-- Service Readmore Start -->
                        <div class="service-readmore-btn">
                            <a href="#" class="readmore-btn">read more</a>
                        </div>
                        <!-- Service Readmore End -->
                    </div>
                    <!-- Service Item End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Service Item Start -->
                    <div class="service-item wow fadeInUp" data-wow-delay="0.4s">
                        <!-- Service Content Start -->
                        <div class="service-item-content">
                            <h3>03.</h3>
                            <h2><a href="#">Online Ordering</a></h2>
                            <p>Fresh, handcrafted bakery items delivered daily.</p>
                        </div>
                        <!-- Service Content End -->
                        
                        <!-- Service Readmore Start -->
                        <div class="service-readmore-btn">
                            <a href="#" class="readmore-btn">read more</a>
                        </div>
                        <!-- Service Readmore End -->
                    </div>
                    <!-- Service Item End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Service Item Start -->
                    <div class="service-item wow fadeInUp" data-wow-delay="0.6s">
                        <!-- Service Content Start -->
                        <div class="service-item-content">
                            <h3>04.</h3>
                            <h2><a href="#">Baking Workshops</a></h2>
                            <p>Fresh, handcrafted bakery items delivered daily.</p>
                        </div>
                        <!-- Service Content End -->
                        
                        <!-- Service Readmore Start -->
                        <div class="service-readmore-btn">
                            <a href="#" class="readmore-btn">read more</a>
                        </div>
                        <!-- Service Readmore End -->
                    </div>
                    <!-- Service Item End -->
                </div>
                
                <div class="col-lg-12">
                    <!-- Section Footer Text Start -->
                    <div class="section-footer-text wow fadeInUp" data-wow-delay="0.8s">
                        <p><span>Free</span>Experience the taste everyone's talking about - <a href="#">come in or order online!</a></p>
                    </div>
                    <!-- Section Footer Text End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Services Section End -->

    <!-- Why Choose Us Section Start -->
    <div class="why-choose-us bg-section">
        <div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <!-- Why Choose Content Start -->
                    <div class="why-choose-content">
                        <!-- Section Title Start -->
                        <div class="section-title">
                            <h3 class="wow fadeInUp">Why choose us</h3>
                            <h2 class="text-anime-style-2" data-cursor="-opaque">Baking freshness & flavor <span>you can trust</span></h2>
                            <p class="wow fadeInUp" data-wow-delay="0.2s">We blend time-honored techniques with the finest ingredients to create elegant pastries and breads that delight the senses.</p>
                        </div>
                        <!-- Section Title End -->
    
                        <!-- Why Choose Item List Start -->
                        <div class="why-choose-item-list">
                            <!-- Why Choose Item Start -->
                            <div class="why-choose-item wow fadeInUp" data-wow-delay="0.4s">
                                <div class="icon-box">
                                    <img src="/images/icon-why-choose-1.svg" alt="">
                                </div>
                                <div class="why-choose-item-content">
                                    <h3>Passion and Care in Every Batch</h3>
                                    <p>Baking isn't just a job for us - it's a craft. We pour love, attention, and expertise into every pastry, bread, and cake.</p>
                                </div>
                            </div>
                            <!-- Why Choose Item End -->
                            
                            <!-- Why Choose Item Start -->
                            <div class="why-choose-item wow fadeInUp" data-wow-delay="0.6s">
                                <div class="icon-box">
                                    <img src="/images/icon-why-choose-2.svg" alt="">
                                </div>
                                <div class="why-choose-item-content">
                                    <h3>Custom Orders for Every Occasion</h3>
                                    <p>Baking isn't just a job for us - it's a craft. We pour love, attention, and expertise into every pastry, bread, and cake.</p>
                                </div>
                            </div>
                            <!-- Why Choose Item End -->
                            
                            <!-- Why Choose Item Start -->
                            <div class="why-choose-item wow fadeInUp" data-wow-delay="0.8s">
                                <div class="icon-box">
                                    <img src="/images/icon-why-choose-3.svg" alt="">
                                </div>
                                <div class="why-choose-item-content">
                                    <h3>Traditional Recipes with a Modern Twist</h3>
                                    <p>Baking isn't just a job for us - it's a craft. We pour love, attention, and expertise into every pastry, bread, and cake.</p>
                                </div>
                            </div>
                            <!-- Why Choose Item End -->
                        </div>
                        <!-- Why Choose Item List End -->
                    </div>
                    <!-- Why Choose Content End -->
                </div>
    
                <div class="col-lg-6">
                    <!-- Why Choose Images Start -->
                    <div class="why-choose-images">
                        <!-- Why Choose Image Box 1 Start -->
                        <div class="why-choose-image-box-1">
                            <!-- Why Choose Image Start -->
                            <div class="why-choose-image wow fadeInUp">
                                <figure class="image-anime">
                                    <img src="/images/why-choose-image-1.jpg" alt="">
                                </figure>
    
                                <!-- Why Choose CTA Box Start -->
                                <div class="why-choose-cta-box">
                                    <div class="icon-box">
                                        <img src="/images/icon-headset.svg" alt="">
                                    </div>
                                    <div class="why-choose-cta-content">
                                        <p>Got questions? we're here to help!</p>
                                    </div>
                                </div>
                                <!-- Why Choose CTA Box End -->
                            </div>
                            <!-- Why Choose Image End -->
                            
                            <div class="google-rating-box wow fadeInUp" data-wow-delay="0.2s">
                                <!-- Google Rating Content Start -->
                                <div class="google-rating-content">
                                    <p><i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                        <i class="fa-solid fa-star"></i>
                                    </p>
                                    <p>More Than 1K+ Trusted Clients</p>
                                </div>
                                <!-- Google Rating Content End -->
    
                                <!-- Review Images Start -->
                                <div class="review-images">
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-1.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-2.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-3.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image">
                                        <figure class="image-anime">
                                            <img src="/images/author-4.jpg" alt="">
                                        </figure>
                                    </div>
                                    <div class="review-image add-more">
                                        <i class="fa-solid fa-plus"></i>
                                    </div>
                                </div>
                                <!-- Review Images End -->
                            </div>
                        </div>
                        <!-- Why Choose Image Box 1 End -->
                        
                        <!-- Why Choose Image Box 2 Start -->
                        <div class="why-choose-image-box-2">
                            <!-- Contact Us Circle Start -->
                            <div class="contact-us-circle">
                                <a href="#"><img src="/images/contact-us-circle.svg" alt=""></a>
                            </div>
                            <!-- Contact Us Circle End -->
                            
                            <!-- Why Choose Image Start -->
                            <div class="why-choose-image">
                                <figure class="image-anime reveal">
                                    <img src="/images/why-choose-image-2.jpg" alt="">
                                </figure>
                            </div>
                            <!-- Why Choose Image End -->
                        </div>
                        <!-- Why Choose Image Box 2 End -->
                    </div>
                    <!-- Why Choose Images End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Why Choose Us Section End -->

    <!-- Our Feature Section Start -->
    <div class="our-features bg-section">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-6">
                    <!-- Feature Content Start -->
                    <div class="feature-content dark-section parallaxie">
                        <!-- Section Title Start -->
                        <div class="section-title">
                            <h3 class="wow fadeInUp">Our features</h3>
                            <h2 class="text-anime-style-2" data-cursor="-opaque">Crafting fresh bakes daily with <span>love and tradition</span></h2>
                            <p class="wow fadeInUp" data-wow-delay="0.2s">From sunrise to oven, we create handcrafted breads, pastries, and cakes using time-honored techniques and premium ingredients. Whether it's a morning croissant, a celebration cake.</p>
                        </div>
                        <!-- Section Title End -->
                        
                        <!-- Feature Button Start -->
                        <div class="feature-btn wow fadeInUp" data-wow-delay="0.4s">
                            <a href="#" class="btn-default btn-highlighted">Learn more</a>
                        </div>
                        <!-- Feature Button End -->
                    </div>
                    <!-- Feature Content End -->
                </div>
                
                <div class="col-lg-6">
                    <!-- Feature Items List Start -->
                    <div class="feature-items-list">
                        <!-- Feature Item Start -->
                        <div class="feature-item wow fadeInUp">
                            <div class="icon-box">
                                <img src="/images/icon-feature-1.svg" alt="">
                            </div>
                            <div class="feature-item-content">
                                <h3>Handcrafted Baked Goods</h3>
                                <p>We create a wide range of breads, pastries, cakes, and dessertsc from scratch.</p>
                            </div>
                        </div>
                        <!-- Feature Item End -->
                        
                        <!-- Feature Item Start -->
                        <div class="feature-item wow fadeInUp" data-wow-delay="0.2s">
                            <div class="icon-box">
                                <img src="/images/icon-feature-2.svg" alt="">
                            </div>
                            <div class="feature-item-content">
                                <h3>Custom Cake Design</h3>
                                <p>Our skilled bakers and decorators craft personalized cakes tailored to your style and occasion.</p>
                            </div>
                        </div>
                        <!-- Feature Item End -->
                        
                        <!-- Feature Item Start -->
                        <div class="feature-item wow fadeInUp" data-wow-delay="0.4s">
                            <div class="icon-box">
                                <img src="/images/icon-feature-3.svg" alt="">
                            </div>
                            <div class="feature-item-content">
                                <h3>Daily Fresh Production</h3>
                                <p>Every morning, we bake fresh batches to ensure our customers always enjoy warm, just-out-of-the-oven.</p>
                            </div>
                        </div>
                        <!-- Feature Item End -->
                        
                        <!-- Feature Item Start -->
                        <div class="feature-item wow fadeInUp" data-wow-delay="0.6s">
                            <div class="icon-box">
                                <img src="/images/icon-feature-4.svg" alt="">
                            </div>
                            <div class="feature-item-content">
                                <h3>Catering & Special Orders</h3>
                                <p>We offer catering services and special bulk orders for events, meetings, and celebrations.</p>
                            </div>
                        </div>
                        <!-- Feature Item End -->
                    </div>
                    <!-- Feature Item List End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Feature Section End -->

    <!-- Our Products Section Start -->
    <div class="our-products bg-section">
        <div class="container">
            <div class="row section-row">
                <div class="col-lg-12">
                    <!-- Section Title Start -->
                    <div class="section-title section-title-center">
                        <h3 class="wow fadeInUp">Our products</h3>
                        <h2 class="text-anime-style-2" data-cursor="-opaque">Artisan baked goods perfect for any occasion or <span>everyday treats</span></h2>
                    </div>
                    <!-- Section Title End -->
                </div>
            </div>

            <div class="row">
                <div class="col-lg-3 col-md-6">
                    <!-- Product Item Start -->
                    <div class="product-item product-box-1 wow fadeInUp">
                        <div class="product-item-image">
                            <figure class="image-anime">
                                <img src="/images/product-1.jpg" alt="">
                            </figure>
                        </div>
                        <div class="product-item-content">
                            <p>01.</p>
                            <h3>Gourmet Cupcakes</h3>
                        </div>
                    </div>
                    <!-- Product Item End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Product Item Start -->
                    <div class="product-item product-box-2 wow fadeInUp" data-wow-delay="0.2s">
                        <div class="product-item-image">
                            <figure class="image-anime">
                                <img src="/images/product-2.jpg" alt="">
                            </figure>
                        </div>
                        <div class="product-item-content">
                            <p>02.</p>
                            <h3>Artisan Breads</h3>
                        </div>
                    </div>
                    <!-- Product Item End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Product Item Start -->
                    <div class="product-item product-box-3 wow fadeInUp" data-wow-delay="0.4s">
                        <div class="product-item-image">
                            <figure class="image-anime">
                                <img src="/images/product-3.jpg" alt="">
                            </figure>
                        </div>
                        <div class="product-item-content">
                            <p>03.</p>
                            <h3>Celebration Cakes</h3>
                        </div>
                    </div>
                    <!-- Product Item End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Product Item Start -->
                    <div class="product-item product-box-4 wow fadeInUp" data-wow-delay="0.6s">
                        <div class="product-item-image">
                            <figure class="image-anime">
                                <img src="/images/product-4.jpg" alt="">
                            </figure>
                        </div>
                        <div class="product-item-content">
                            <p>04.</p>
                            <h3>Delicious Pastries</h3>
                        </div>
                    </div>
                    <!-- Product Item End -->
                </div>

                <div class="col-lg-12">
                    <!-- Section Footer Text Start -->
                    <div class="section-footer-text wow fadeInUp" data-wow-delay="0.8s">
                        <p>Feel the freedom of the open trail - <a href="#">Start your riding journey with us now!</a></p>
                    </div>
                    <!-- Section Footer Text End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Products Section End -->

    <!-- How It Work Section Start -->
    <div class="how-it-work bg-section dark-section">
        <div class="container">
            <div class="row section-row">
                <div class="col-lg-12">
                    <!-- Section Title Start -->
                    <div class="section-title section-title-center">
                        <h3 class="wow fadeInUp">How it works</h3>
                        <h2 class="text-anime-style-2" data-cursor="-opaque">Fresh bakes made simple - from our oven to <span>your doorstep</span></h2>
                    </div>
                    <!-- Section Title End -->
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-3 col-md-6">
                    <!-- How It Work Content Start -->
                    <div class="work-content-box wow fadeInUp">
                        <h3>Getting fresh baked goods is easier</h3>
                        <p>We've made it simple for you to enjoy bakery-fresh delights.</p>
                        <ul>
                            <li>Explore our menu filled</li>
                            <li>Daily Fresh Production</li>
                        </ul>
                        <a href="#" class="btn-default btn-highlighted">contact us</a>
                    </div>
                    <!-- How It Work Content End -->
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <!-- Work Step Item Start -->
                    <div class="work-step-item wow fadeInUp" data-wow-delay="0.2s">
                        <div class="work-step-image">
                            <figure class="image-anime">
                                <img src="/images/work-step-image-1.jpg" alt="">
                            </figure>
                        </div>
                        <div class="work-step-no">
                            <h2>01</h2>
                        </div>
                        <div class="work-step-content">
                            <h3>Pick Your Treats</h3>
                            <p>Explore our menu filled with delicious pastries.</p>
                        </div>
                    </div>
                </div>
                <!-- Work Step Item End -->
                
                <div class="col-lg-3 col-md-6">
                    <!-- Work Step Item Start -->
                    <div class="work-step-item wow fadeInUp" data-wow-delay="0.4s">
                        <div class="work-step-image">
                            <figure class="image-anime">
                                <img src="/images/work-step-image-2.jpg" alt="">
                            </figure>
                        </div>
                        <div class="work-step-no">
                            <h2>02</h2>
                        </div>
                        <div class="work-step-content">
                            <h3>Confirm Your Order</h3>
                            <p>Choose your pickup or delivery place.</p>
                        </div>
                    </div>
                </div>
                <!-- Work Step Item End -->
                
                <div class="col-lg-3 col-md-6">
                    <!-- Work Step Item Start -->
                    <div class="work-step-item wow fadeInUp" data-wow-delay="0.6s">
                        <div class="work-step-image">
                            <figure class="image-anime">
                                <img src="/images/work-step-image-3.jpg" alt="">
                            </figure>
                        </div>
                        <div class="work-step-no">
                            <h2>03</h2>
                        </div>
                        <div class="work-step-content">
                            <h3>Enjoy the Freshness</h3>
                            <p>Savor every bite made with fresh ingredients.</p>
                        </div>
                    </div>
                    <!-- Work Step Item End -->
                </div>
            </div>
        </div>
    </div>
    <!-- How It Work Section End- -->

    <!-- Our Special Offers Section Start -->
    <div class="our-special-offers bg-section">
        <div class="container">
            <div class="row section-row">
                <div class="col-lg-12">
                    <!-- Section Title Start -->
                    <div class="section-title section-title-center">
                        <h3 class="wow fadeInUp">Special offers</h3>
                        <h2 class="text-anime-style-2" data-cursor="-opaque">Sweet savings and limited-time treats you <span>don't want to miss</span></h2>
                    </div>
                    <!-- Section Title End -->
                </div>
            </div>
            
            <div class="row align-items-center">
                <div class="col-lg-4 col-md-6 order-1">
                    <!-- Offers Item List Start -->
                    <div class="offers-item-list offer-list-1">
                        <!-- Offers Item Start -->
                        <div class="offer-item wow fadeInUp">
                            <div class="offer-image">
                                <figure>
                                    <img src="/images/best-product-1.png" alt="">
                                </figure>
                            </div>
                            <div class="offer-item-content">
                                <h2>Cupcake</h2>
                                <p>Delightfully moist and perfectly portioned.</p>
                                <h3>Price: $24.95</h3>
                            </div>
                        </div>
                        <!-- Offers Item End -->
                        
                        <!-- Offers Item Start -->
                        <div class="offer-item wow fadeInUp" data-wow-delay="0.2s">
                            <div class="offer-image">
                                <figure>
                                    <img src="/images/best-product-2.png" alt="">
                                </figure>
                            </div>
                            <div class="offer-item-content">
                                <h2>Multigrain Loaf</h2>
                                <p>A wholesome blend of grains and seeds baked to perfection</p>
                                <h3>Price: $55.00</h3>
                            </div>
                        </div>
                        <!-- Offers Item End -->
                        
                        <!-- Offers Item Start -->
                        <div class="offer-item wow fadeInUp" data-wow-delay="0.4s">
                            <div class="offer-image">
                                <figure>
                                    <img src="/images/best-product-3.png" alt="">
                                </figure>
                            </div>
                            <div class="offer-item-content">
                                <h2>Cinnamon Roll</h2>
                                <p>Soft, fluffy rolls swirled with cinnamon and sugar.</p>
                                <h3>Price: $39.95</h3>
                            </div>
                        </div>
                        <!-- Offers Item End -->
                    </div>
                    <!-- Offers Item List End -->
                </div>

                <div class="col-lg-4 order-lg-2 order-md-3 order-2">
                    <!-- Best Offer Image Box Start -->
                    <div class="best-offer-image-box">
                        <!-- Best Offer Content Start -->
                        <div class="best-offer-content wow fadeInUp">
                            <h2>Best offers</h2>
                            <p>A Little Thank You, from Us to You!</p>
                            <a href="#" class="readmore-btn">View all offers</a>
                        </div>
                        <!-- Best Offer Content End -->
                        
                        <!-- Best Offer Image Start -->
                        <div class="best-offer-image wow fadeInUp" data-wow-delay="0.2s">
                            <figure>
                                <img src="/images/best-offer-image.png" alt="">
                            </figure>
                        </div>
                        <!-- Best Offer Image End -->
                    </div>
                    <!-- Best Offer Image Box End -->
                </div>
                
                <div class="col-lg-4 col-md-6 order-lg-3 order-md-2 order-3">
                    <!-- Offers Item List Start -->
                    <div class="offers-item-list offer-list-2">
                        <!-- Offers Item Start -->
                        <div class="offer-item wow fadeInUp">
                            <div class="offer-image">
                                <figure>
                                    <img src="/images/best-product-4.png" alt="">
                                </figure>
                            </div>
                            <div class="offer-item-content">
                                <h2>Cheesecake</h2>
                                <p>Creamy smooth cheesecake on butter graham cracker crust</p>
                                <h3>Price: $50.00</h3>
                            </div>
                        </div>
                        <!-- Offers Item End -->
                        
                        <!-- Offers Item Start -->
                        <div class="offer-item wow fadeInUp" data-wow-delay="0.2s">
                            <div class="offer-image">
                                <figure>
                                    <img src="/images/best-product-5.png" alt="">
                                </figure>
                            </div>
                            <div class="offer-item-content">
                                <h2>Red Velvet Cupcake</h2>
                                <p>Moist & vibrant red velvet cake topped with rich velvety cream</p>
                                <h3>Price: $70.50</h3>
                            </div>
                        </div>
                        <!-- Offers Item End -->
                        
                        <!-- Offers Item Start -->
                        <div class="offer-item wow fadeInUp" data-wow-delay="0.4s">
                            <div class="offer-image">
                                <figure>
                                    <img src="/images/best-product-6.png" alt="">
                                </figure>
                            </div>
                            <div class="offer-item-content">
                                <h2>Cheese Scone</h2>
                                <p>Buttery, crumbly scones baked with sharp cheddar cheese.</p>
                                <h3>Price: $60.00</h3>
                            </div>
                        </div>
                        <!-- Offers Item End -->
                    </div>
                    <!-- Offers Item List End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Special Offers Section End -->

    <!-- Our Gallery Section Start -->
    <div class="our-gallery bg-section">
        <div class="container-fluid">
            <div class="row section-row">
                <div class="col-lg-12">
                    <!-- Section Title Start -->
                    <div class="section-title section-title-center">
                        <h3 class="wow fadeInUp">Our gallery</h3>
                        <h2 class="text-anime-style-2" data-cursor="-opaque">Feast your eyes on our fresh creations and <span>bakery delights</span></h2>
                    </div>
                    <!-- Section Title End -->
                </div>
            </div>

            <div class="row no-gutters">
                <div class="col-lg-12">
                    <!-- Gallery Slider Start -->
                    <div class="gallery-slider">
                        <div class="swiper">
                            <div class="swiper-wrapper gallery-items" data-cursor-text="Drag">
                                <!-- Gallery Slide Start -->
                                <div class="swiper-slide">
                                    <!-- Image Gallery start -->
                                    <div class="photo-gallery">
                                        <a href="/images/gallery-1.jpg" data-cursor-text="View">
                                            <figure>
                                                <img src="/images/gallery-1.jpg" alt="">
                                            </figure>
                                        </a>
                                    </div>
                                    <!-- Image Gallery end -->
                                </div>
                                <!-- Gallery Slide End -->

                                <!-- Gallery Slide Start -->
                                <div class="swiper-slide">
                                    <!-- Image Gallery start -->
                                    <div class="photo-gallery">
                                        <a href="/images/gallery-2.jpg" data-cursor-text="View">
                                            <figure>
                                                <img src="/images/gallery-2.jpg" alt="">
                                            </figure>
                                        </a>
                                    </div>
                                    <!-- Image Gallery end -->
                                </div>
                                <!-- Gallery Slide End -->

                                <!-- Gallery Slide Start -->
                                <div class="swiper-slide">
                                    <!-- Image Gallery start -->
                                    <div class="photo-gallery">
                                        <a href="/images/gallery-3.jpg" data-cursor-text="View">
                                            <figure>
                                                <img src="/images/gallery-3.jpg" alt="">
                                            </figure>
                                        </a>
                                    </div>
                                    <!-- Image Gallery end -->
                                </div>
                                <!-- Gallery Slide End -->

                                <!-- Gallery Slide Start -->
                                <div class="swiper-slide">
                                    <!-- Image Gallery start -->
                                    <div class="photo-gallery">
                                        <a href="/images/gallery-4.jpg" data-cursor-text="View">
                                            <figure>
                                                <img src="/images/gallery-4.jpg" alt="">
                                            </figure>
                                        </a>
                                    </div>
                                    <!-- Image Gallery end -->
                                </div>
                                <!-- Gallery Slide End -->

                                <!-- Gallery Slide Start -->
                                <div class="swiper-slide">
                                    <!-- Image Gallery start -->
                                    <div class="photo-gallery">
                                        <a href="/images/gallery-5.jpg" data-cursor-text="View">
                                            <figure>
                                                <img src="/images/gallery-5.jpg" alt="">
                                            </figure>
                                        </a>
                                    </div>
                                    <!-- Image Gallery end -->
                                </div>
                                <!-- Gallery Slide End -->

                                <!-- Gallery Slide Start -->
                                <div class="swiper-slide">
                                    <!-- Image Gallery start -->
                                    <div class="photo-gallery">
                                        <a href="/images/gallery-6.jpg" data-cursor-text="View">
                                            <figure>
                                                <img src="/images/gallery-6.jpg" alt="">
                                            </figure>
                                        </a>
                                    </div>
                                    <!-- Image Gallery end -->
                                </div>
                                <!-- Gallery Slide End -->
                            </div>
                            
                            <!-- Gallery Button Start -->
                            <div class="gallery-btn">
                                <div class="gallery-button-prev"></div>
                                <div class="gallery-button-next"></div>
                            </div>
                            <!-- Gallery Button End -->
                        </div>
                    </div>
                    <!-- Gallery Slider End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Gallery Section End -->

    <!-- Our Testimonials Section Start -->
    <div class="our-testimonials bg-section dark-section">
        <div class="container">
            <div class="row">
                <div class="col-lg-7">
                    <!-- Testimonials Content Start -->
                    <div class="testimonials-content">
                        <!-- Section Title Start -->
                        <div class="section-title">
                            <h3 class="wow fadeInUp">Our testimonials</h3>
                            <h2 class="text-anime-style-2" data-cursor="-opaque">Hear what our customers say <span>about us</span></h2>
                        </div>
                        <!-- Section Title End -->

                        <!-- Testimonial Slider Start -->
                        <div class="testimonial-slider">
                            <div class="swiper">
                                <div class="swiper-wrapper" data-cursor-text="Drag">
                                    <!-- Testimonial Slide Start -->
                                    <div class="swiper-slide">
                                        <!-- Testimonial Item Start -->
                                        <div class="testimonial-item">
                                            <!-- Testimonial Content Start -->
                                            <div class="testimonial-item-content">
                                                <p>“ I attended the Rio Carnal last February and it was a life-changing experience. The music, the energy, - everything was electric. I felt completely immersed in Brazilian culture. I attended the Rio Carnival last February. ”</p>
                                            </div>
                                            <!-- Testimonial Content End -->
                                            
                                            <!-- Testimonial Body Start -->
                                            <div class="testimonial-author">
                                                <div class="author-image">
                                                    <figure class="image-anime">
                                                        <img src="/images/author-1.jpg" alt="">
                                                    </figure>
                                                </div>            
                                                <div class="author-content">
                                                    <h3>Darlene Robertson</h3>
                                                    <p>Regular Customer</p>
                                                </div>
                                            </div>
                                            <!-- Testimonial Body End -->
                                        </div>
                                        <!-- Testimonial Item End -->
                                    </div>
                                    <!-- Testimonial Slide End -->
                                    
                                    <!-- Testimonial Slide Start -->
                                    <div class="swiper-slide">
                                        <!-- Testimonial Item Start -->
                                        <div class="testimonial-item">
                                            <!-- Testimonial Content Start -->
                                            <div class="testimonial-item-content">
                                                <p>“ I attended the Rio Carnal last February and it was a life-changing experience. The music, the energy, - everything was electric. I felt completely immersed in Brazilian culture. I attended the Rio Carnival last February. ”</p>
                                            </div>
                                            <!-- Testimonial Content End -->
                                            
                                            <!-- Testimonial Body Start -->
                                            <div class="testimonial-author">
                                                <div class="author-image">
                                                    <figure class="image-anime">
                                                        <img src="/images/author-2.jpg" alt="">
                                                    </figure>
                                                </div>            
                                                <div class="author-content">
                                                    <h3>Olivia Clarke</h3>
                                                    <p>General Manager</p>
                                                </div>
                                            </div>
                                            <!-- Testimonial Body End -->
                                        </div>
                                        <!-- Testimonial Item End -->
                                    </div>
                                    <!-- Testimonial Slide End -->
                                </div>
                            </div>
                        </div>
                        <!-- Testimonial Slider End -->

                        <!-- Testimonial Footer Start -->
                        <div class="testimonials-counter-list">
                            <!-- Testimonials Counter Item Start -->
                            <div class="testimonial-counter-item">
                                <!-- Testimonials Counter Header Start -->
                                <div class="testimonial-counter-header">
                                    <div class="icon-box">
                                        <img src="/images/icon-testimonial-counter-1.svg" alt="">
                                    </div>
                                    <div class="testimonial-counter-title">
                                        <h2><span class="counter">15</span>K+</h2>
                                    </div>
                                </div>
                                <!-- Testimonials Counter Header End -->

                                <!-- Testimonials Counter Body Start -->
                                <div class="testimonial-counter-body">
                                    <p>From breads to cakes From breads to cakes</p>
                                </div>
                                <!-- Testimonials Counter Body End -->
                            </div>
                            <!-- Testimonials Counter Item End -->

                            <!-- Testimonials Counter Item Start -->
                            <div class="testimonial-counter-item">
                                <!-- Testimonials Counter Header Start -->
                                <div class="testimonial-counter-header">
                                    <div class="icon-box">
                                        <img src="/images/icon-testimonial-counter-2.svg" alt="">
                                    </div>
                                    <div class="testimonial-counter-title">
                                        <h2><span class="counter">98</span>%</h2>
                                    </div>
                                </div>
                                <!-- Testimonials Counter Header End -->

                                <!-- Testimonials Counter Body Start -->
                                <div class="testimonial-counter-body">
                                    <p>From breads to cakes From breads to cakes</p>
                                </div>
                                <!-- Testimonials Counter Body End -->
                            </div>
                            <!-- Testimonials Counter Item End -->

                            <!-- Testimonials Counter Item Start -->
                            <div class="testimonial-counter-item">
                                <!-- Testimonials Counter Header Start -->
                                <div class="testimonial-counter-header">
                                    <div class="icon-box">
                                        <img src="/images/icon-testimonial-counter-3.svg" alt="">
                                    </div>
                                    <div class="testimonial-counter-title">
                                        <h2><span class="counter">25</span>+</h2>
                                    </div>
                                </div>
                                <!-- Testimonials Counter Header End -->

                                <!-- Testimonials Counter Body Start -->
                                <div class="testimonial-counter-body">
                                    <p>From breads to cakes From breads to cakes</p>
                                </div>
                                <!-- Testimonials Counter Body End -->
                            </div>
                            <!-- Testimonials Counter Item End -->
                        </div>
                        <!-- Testimonial Footer End -->
                    </div>
                    <!-- Testimonials Content End -->
                </div>

                <div class="col-lg-5">
                    <!-- Testimonials Image Start -->
                    <div class="testimonials-image wow fadeInUp" data-wow-delay="0.2s">
                        <div class="testimonial-img">
                            <figure class="image-anime">
                                <img src="/images/testimonial-image.jpg" alt="">
                            </figure>
                        </div>

                        <!-- Why Choose CTA Box Start -->
                        <div class="why-choose-cta-box testimonial-cta-box">
                            <div class="icon-box">
                                <img src="/images/icon-headset.svg" alt="">
                            </div>
                            <div class="why-choose-cta-content">
                                <p>Need Answers? Let's Clear Things Up For You!</p>
                                <h3><a href="tel:123465789">+(123) 465-789</a></h3>
                            </div>
                        </div>
                        <!-- Why Choose CTA Box End -->
                    </div>
                    <!-- Testimonials Image End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Testimonials Section End -->

    <!-- Our Blog Section Start -->
    <div class="our-blog bg-section">
        <div class="container">
            <div class="row section-row">
                <div class="col-lg-12">
                    <!-- Section Title Start -->
                    <div class="section-title section-title-center">
                        <h3 class="wow fadeInUp">Latest blog</h3>
                        <h2 class="text-anime-style-2" data-cursor="-opaque">Inspiration, recipes, and behind the <span>scenes moments</span></h2>
                    </div>
                    <!-- Section Title End -->
                </div>
            </div>
            
            <div class="row">
                <div class="col-lg-4 col-md-6">
                    <!-- Post Item Start -->
                    <div class="post-item wow fadeInUp">
                        <!-- Post Featured Image Start-->
                        <div class="post-featured-image">
                            <a href="#" data-cursor-text="View">
                                <figure class="image-anime">
                                    <img src="/images/post-1.jpg" alt="">
                                </figure>
                            </a>
                        </div>
                        <!-- Post Featured Image End -->

                        <!-- Post Item Body Start -->
                        <div class="post-item-body">
                            <!-- Post Item Content Start -->
                            <div class="post-item-content">
                                <h2><a href="#">Why We Only Bake With Real Butter - And You Should Too</a></h2>
                            </div>
                            <!-- Post Item Content End -->

                            <!-- Post Item Readmore Button Start-->
                            <div class="post-item-btn">
                                <a href="#" class="readmore-btn">read more</a>
                            </div>
                            <!-- Post Item Readmore Button End-->
                        </div>
                        <!-- Post Item Body End -->
                    </div>
                    <!-- Post Item End -->
                </div>

                <div class="col-lg-4 col-md-6">
                    <!-- Post Item Start -->
                    <div class="post-item wow fadeInUp" data-wow-delay="0.2s">
                       <!-- Post Featured Image Start-->
                       <div class="post-featured-image">
                            <a href="#" data-cursor-text="View">
                                <figure class="image-anime">
                                    <img src="/images/post-2.jpg" alt="">
                                </figure>
                            </a>
                        </div>
                        <!-- Post Featured Image End -->

                        <!-- Post Item Body Start -->
                        <div class="post-item-body">
                            <!-- Post Item Content Start -->
                            <div class="post-item-content">
                                <h2><a href="#">Behind the Scenes A Full Day in the Life of a Small Artisan Bakery</a></h2>
                            </div>
                            <!-- Post Item Content End -->

                            <!-- Post Item Readmore Button Start-->
                            <div class="post-item-btn">
                                <a href="#" class="readmore-btn">read more</a>
                            </div>
                            <!-- Post Item Readmore Button End-->
                        </div>
                        <!-- Post Item Body End -->
                    </div>
                    <!-- Post Item End -->
                </div>

                <div class="col-lg-4 col-md-6">
                    <!-- Post Item Start -->
                    <div class="post-item wow fadeInUp" data-wow-delay="0.4s">
                        <!-- Post Featured Image Start-->
                        <div class="post-featured-image">
                            <a href="#" data-cursor-text="View">
                                <figure class="image-anime">
                                    <img src="/images/post-3.jpg" alt="">
                                </figure>
                            </a>
                        </div>
                        <!-- Post Featured Image End -->

                        <!-- Post Item Body Start -->
                        <div class="post-item-body">
                            <!-- Post Item Content Start -->
                            <div class="post-item-content">
                                <h2><a href="#">Seasonal Baking Favorites You'll Only Find in Our Shop This Time of Year</a></h2>
                            </div>
                            <!-- Post Item Content End -->

                            <!-- Post Item Readmore Button Start-->
                            <div class="post-item-btn">
                                <a href="#" class="readmore-btn">read more</a>
                            </div>
                            <!-- Post Item Readmore Button End-->
                        </div>
                        <!-- Post Item Body End -->
                    </div>
                    <!-- Post Item End -->
                </div>
            </div>
        </div>
    </div>
    <!-- Our Blog Section End -->

    <!-- Footer Start -->
    <footer class="main-footer bg-section dark-section">
		<div class="container">
            <div class="row">
                <div class="col-lg-6">
                    <!-- Footer About Start -->
                    <div class="footer-about">
                        <!-- Footer Logo Start -->
                        <div class="footer-logo">
                            <img src="/images/footer-logo.svg" alt="">
                        </div>
                        <!-- Footer Logo End -->

                        <!-- Footer Menu Start -->
                        <div class="footer-menu">
                            <ul>
                                <li><a href="#">Home</a></li>
                                <li><a href="#">About us</a></li>
                                <li><a href="#">services</a></li>
                                <li><a href="#">Gallery</a></li>
                                <li><a href="#">Contact</a></li>
                            </ul>
                        </div>
                        <!-- Footer Menu End -->
                        
                        <!-- Footer Contact Item Start -->
                        <div class="footer-contact-item">
                            <h3><a href="tel:+123456789">(+123) 456-789</a></h3>
                            <p>4517 Washington Ave. Manchester, Kentucky 39495</p>
                        </div>
                        <!-- Footer Contact Item End -->
                    </div>
                    <!-- Footer About End -->
                </div>

                <div class="col-lg-6">
                    <!-- Footer Newsletter Form Start -->
                    <div class="footer-newsletter-form">
                        <!-- Footer Newsletter Info Start -->
                        <div class="footer-newsletter-info">
                            <h3>Subscribe To Our Newsletter!</h3>
                            <p>Freshly baked goodness crafted with love in every single bite</p>
                        </div>
                        <!-- Footer Newsletter Info End -->
                        
                        <!-- Newsletter Form Start -->
                        <div class="newsletter-form">
                            <form id="newslettersForm" action="#" method="POST">
                                <div class="form-group">
                                    <input type="email" name="mail" class="form-control"  id="mail" placeholder="Enter Your Email Address *" required>
                                    <button type="submit" class="btn-default btn-highlighted">Subscribe Now</button>
                                </div>
                            </form>
                        </div>
                        <!-- Newsletter Form End -->
                        
                        <!-- Footer Social Links Start -->
                        <div class="footer-social-links">
                            <h3>Follow Us On Socials:</h3>
                            <ul>
                                <li><a href="#"><i class="fa-brands fa-instagram"></i></a></li>
                                <li><a href="#"><i class="fa-brands fa-facebook-f"></i></a></li>
                                <li><a href="#"><i class="fa-brands fa-dribbble"></i></a></li>
                                <li><a href="#"><i class="fa-brands fa-linkedin-in"></i></a></li>
                            </ul>
                        </div>
                        <!-- Footer Social Links End -->
                    </div>
                    <!-- Footer Newsletter Form End -->
                </div>

                <div class="col-lg-12">
                    <!-- Footer Copyright Start -->
                    <div class="footer-copyright">
                        <!-- Footer Copyright Text Start -->
                        <div class="footer-copyright-text">
                            <p>Copyright © 2025 All Rights Reserved.</p>
                        </div>
                        <!-- Footer Copyright Text End -->
                        
                        <!-- Footer Privacy Policy Start -->
                        <div class="footer-privacy-policy">
                            <ul>
                                <li><a href="#">Privacy Policy</a></li>
                                <li><a href="#">Legal Information</a></li>
                            </ul>
                        </div>
                        <!-- Footer Privacy Policy End -->
                    </div>
                    <!-- Footer Copyright End -->
                </div>
            </div>
        </div>
    </footer>
    <!-- Footer End -->

    </div>
</template>
