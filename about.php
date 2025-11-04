<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>About Us - NeinMaid</title>
  <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Roboto', sans-serif;
      background: #f9f9f9;
      margin: 0;
      padding: 0;
      color: #333;
    }
    .navbar {
      background: white;
      padding: 15px 40px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .navbar h2 {
      color: #dd6f6f;
    }
    .navbar a {
      text-decoration: none;
      color: #333;
      font-weight: 500;
      transition: color 0.3s;
    }
    .navbar a:hover {
      color: #dd6f6f;
    }
    .container {
      max-width: 1000px;
      margin: 40px auto;
      padding: 30px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    h1, h2 {
      color: #dd6f6f;
      margin-bottom: 15px;
    }
    h2 {
      margin-top: 35px;
    }
    p {
      line-height: 1.7;
      margin-bottom: 15px;
      color: #555;
    }
    ul {
      margin-left: 20px;
      margin-bottom: 15px;
    }
    li {
      margin-bottom: 8px;
    }
    .footer {
      text-align: center;
      padding: 20px;
      background: #333;
      color: white;
      margin-top: 40px;
    }
    .footer a {
      color: #dd6f6f;
      text-decoration: none;
    }
  </style>
</head>
<body>
  <!-- Navbar -->
  <div class="navbar">
    <h2>NeinMaid</h2>
    <a href="user_dashboard.php">⬅ Back to Dashboard</a>
  </div>

  <!-- Content -->
  <div class="container">
    <h1>Welcome to NEINMAID – Professional Maid Cleaning Services You Can Rely On</h1>
    <p>At NEINMAID, we understand how valuable your time is. Between work, family, and daily responsibilities, keeping your home clean can feel overwhelming. That’s where we come in.</p>
    <p>We are a trusted team of professional cleaners dedicated to helping you maintain a spotless, fresh, and healthy living environment – without the stress. Whether you need a one-time deep clean, regular weekly visits, or move-in/move-out cleaning, we’ve got you covered.</p>

    <h2>Our Story</h2>
    <p>NEINMAID was founded in 2025 with a simple goal – to make professional cleaning services accessible, reliable, and affordable for everyone. What started as a small team with a passion for cleanliness has grown into a trusted name in Penang, known for attention to detail, friendly service, and excellent results.</p>
    <p>Over the years, we’ve built long-lasting relationships with our clients – some who have been with us since the very beginning. We take pride in being more than just a cleaning company – we’re a team that truly cares about the people we serve.</p>

    <h2>Our Mission</h2>
    <p>To deliver high-quality, dependable maid services that give our clients peace of mind, more free time, and a healthier home.</p>

    <h2>Our Values</h2>
    <ul>
      <li><strong>Trust & Transparency:</strong> All our cleaners are background-checked, professionally trained, and committed to treating your home with care and respect.</li>
      <li><strong>Consistency & Quality:</strong> We follow detailed cleaning checklists and use high-grade tools and eco-friendly products.</li>
      <li><strong>Flexibility & Convenience:</strong> We offer customized cleaning plans that fit your schedule, lifestyle, and preferences.</li>
      <li><strong>Customer Satisfaction:</strong> Your happiness is our top priority. If you’re not satisfied, we’ll make it right.</li>
    </ul>

    <h2>What We Offer</h2>
    <ul>
      <li>Standard Home Cleaning</li>
      <li>Deep Cleaning</li>
      <li>Move-In / Move-Out Cleaning</li>
      <li>Office & Commercial Cleaning</li>
      <li>Airbnb / Short-Term Rental Cleaning</li>
      <li>Custom Cleaning Plans (weekly, bi-weekly, monthly)</li>
      <li>Spring/Deep Cleansing</li>
    </ul>

    <h2>Why Choose Us</h2>
    <ul>
      <li>Professional and friendly cleaners</li>
      <li>Affordable and honest pricing</li>
      <li>Safe, eco-friendly cleaning supplies</li>
      <li>Flexible booking options</li>
      <li>Fully insured and reliable service</li>
      <li>Satisfaction guaranteed</li>
    </ul>

    <h2>Let’s Work Together</h2>
    <p>We’re here to help make your life easier – one clean room at a time. Whether you need help once a week or once in a while, NEINMAID is ready to deliver exceptional service with a smile.</p>
    <p><strong>Contact us today and discover how easy and stress-free cleaning can be.</strong></p>
  </div>

  <!-- Footer -->
  <div class="footer">
    <p>© 2025 Copyright: <a href="#">NeinMaidservice.com</a></p>
  </div>
</body>
</html>
