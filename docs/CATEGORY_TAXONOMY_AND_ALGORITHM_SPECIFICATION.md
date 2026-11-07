# Master Category Taxonomy & Recommendation Algorithm Specification

> **Document Classification**: Official E-Commerce Taxonomy & Algorithm Architecture  
> **Target Scope**: Master Category Hierarchy, Level-2 Subcategories, Multi-Factor Feed Ranking Formula, Cold-Start Handling, and Thesis Governance Integration.

---

## 1. The Taxonomy Architecture: 2 Visual Levels + Dynamic Attributes

To eliminate mobile browsing friction (avoiding nested 3-tier and 4-tier click exhaustion) while maximizing personalization data for recommendation engines, BagooPH operates on a **2-Level Controlled Taxonomy + Dynamic Facet Attributes**:

```
                         LEVEL 1: MASTER DEPARTMENT
                            (14 Curated Categories)
                                       │
                                       ▼
                         LEVEL 2: SUBCATEGORY CHOICES
                           (Platform-Standardized)
                                       │
                    ┌──────────────────┴──────────────────┐
                    ▼                                     ▼
        SELLER PRODUCT ASSIGNMENT               BUYER ONE-TAP FILTER CHIP
      • Selected from managed list            • Interactive pill beneath header
      • Prevents spelling/duplicate chaos     • Instant client-side feed filter
                                       │
                                       ▼
                       LAYER 3: SEARCHABLE ATTRIBUTES & TAGS
             • Fit: [Boxy / Oversized] [Relaxed] [Slim]
             • Material: [Heavyweight 240gsm Cotton] [Ripstop] [Linen]
             • Aesthetic / Niche: [Everyday Carry / EDC] [Streetwear] [Tactical]
```

---

## 2. Master Category & Level-2 Subcategory Catalog

Below is the complete 14-category master catalog and corresponding Level-2 subcategories designed for mass-market and high-intent niche buyers:

| # | Master Category (Level 1) | Slug | Level 2 Subcategories (Platform Standardized) |
|---|---|---|---|
| **1** | **Men's Apparel** | `mens-apparel` | <ul><li>Tees & Tops (Heavyweight Boxy Tees, Oversized Graphic, Polos)</li><li>Outerwear (Windbreakers, Zip Hoodies, Overshirts)</li><li>Pants & Bottoms (Tactical Cargo, Relaxed Chinos, Shorts)</li><li>Underwear & Basics (Seamless Briefs, Boxers, Undershirts)</li><li>Headwear (5-Panel Camp Caps, Dad Hats, Tactical Beanies)</li><li>Footwear (Minimalist Sneakers, Sliders, Casual Loafers)</li></ul> |
| **2** | **Bags & Everyday Carry (EDC)** | `backpacks-and-bags` | <ul><li>Crossbody Slings & Chest Packs</li><li>Commuter Backpacks & Roll-Tops</li><li>Modular Tech Pouches & Cable Organizers</li><li>Wallets & Leathercraft (Bifolds, RFID Cardholders)</li><li>EDC Hardware (Anodized Carabiners, Key Organizers)</li><li>Travel & Duffel Bags</li></ul> |
| **3** | **Electronics & Gadgets** | `electronics-and-gadgets` | <ul><li>Power & Charging (GaN Chargers, MagSafe Power Banks, Cables)</li><li>Audio & Sound (TWS Earbuds, Over-Ear Headphones, Bluetooth)</li><li>Mobile Accessories (Shockproof Cases, Screen Protectors)</li><li>Desk Setup (Desk Mats, Mechanical Keyboards, Wireless Mice)</li><li>Smart Living & Ambient Lighting</li><li>Content Creation (Tripods, Ring Lights, Microphones)</li></ul> |
| **4** | **Jewelry & Watches** | `jewelry-and-watches` | <ul><li>Tactical & Field Watches</li><li>Automatic Divers & Chronographs</li><li>Watch Straps (NATO Nylon, FKM Rubber, Leather Rolls)</li><li>Paracord Survival Bracelets & Cuffs</li><li>Chains, Rings & Pendants</li></ul> |
| **5** | **Sports, Outdoor & Tactical** | `sports-and-outdoors` | <ul><li>Tactical Wear (Cobra Belts, Touchscreen Gloves, Arm Sleeves)</li><li>Camping & Trekking (Ultralight Tarps, Stoves, Mess Kits)</li><li>Hydration (Double-Wall Insulated Tumblers, Flasks)</li><li>Multi-Tools & Hardware (Pocket Pliers, Pry Bars, Knives)</li><li>Fitness Gear (Resistance Bands, Jump Ropes)</li><li>Cycling Accessories (Bicycle Lights, Frame Bags, Pumps)</li></ul> |
| **6** | **Eyewear & Accessories** | `bagoo-demo-accessories-5` | <ul><li>Polarized Sunglasses (Aviators, Wayfarers, Wraparounds)</li><li>Blue-Light & Computer Glasses</li><li>Eyewear Storage (Crushproof Hard-Cases, Microfiber Kits)</li><li>Apparel Accessories (Utility Belts, Patches, Bandanas)</li></ul> |
| **7** | **Home, Studio & Living** | `home-and-garden` | <ul><li>Aromatherapy (Hand-Poured Soy Candles, Reed Diffusers)</li><li>Coffee & Tableware (Ceramic Mugs, Pour-Over Drippers)</li><li>Desk Organization (Monitor Stands, Wooden Valet Trays)</li><li>Ambient Lighting & Desk Lamps</li><li>Linens & Quick-Dry Microfiber Throws</li></ul> |
| **8** | **Women's Apparel & Fashion** | `womens-apparel` | <ul><li>Tops & Blouses (Linen Tops, Crop Tops, Blouses)</li><li>Dresses & Skirts (Maxi Dresses, Slip Dresses, A-Line)</li><li>Outerwear (Cardigans, Lightweight Jackets)</li><li>Pants & Trousers (Wide-Leg Pants, High-Waist Denim)</li><li>Loungewear & Intimates (Seamless Bralettes, Pajama Sets)</li><li>Shoulder Bags & Everyday Totes</li></ul> |
| **9** | **Health, Grooming & Beauty** | `health-and-beauty` | <ul><li>Men's Grooming (Matte Hair Clay, Beard Oils, Safety Razors)</li><li>Skincare (Foaming Cleansers, SPF50 Sunscreen, Serums)</li><li>Fragrances (Solid Pocket Colognes, Eau de Parfum)</li><li>Personal Care Appliances (Electric Trimmers)</li><li>Daily Wellness & Hydration Supplements</li></ul> |
| **10** | **Automotive & Motorcycle Gear** | `automotive-motorcycle-group-9` | <ul><li>Rider Protective Gear (Riding Gloves, Balaclavas, Armor)</li><li>Cockpit Mounts (Vibration-Dampening Phone Mounts)</li><li>Helmets & Visor Accessories (Anti-Fog Films)</li><li>Car & Bike Detailing (Edgeless Microfiber, Ceramic Spray)</li><li>Motorcycle Luggage (Tail Bags, Tank Bags, Cargo Nets)</li></ul> |
| **11** | **Food, Gourmet & Coffee** | `food-and-gourmet` | <ul><li>Philippine Single-Origin Coffee (Benguet Arabica, Sagada Dark)</li><li>Local Treats & Snacks (Davao Dark Chocolate, Dried Mangoes)</li><li>Artisan Condiments (Native Honey, Spiced Vinegar, Chili Oil)</li><li>Cold Brew Concentrates & Herbal Teas</li></ul> |
| **12** | **Office & School Supplies** | `office-and-school-supplies-group-12` | <ul><li>Notebooks & Journals (Dot-Grid Bullet Journals, Planners)</li><li>Writing Instruments (Rollerballs, Brass Pens, Pencils)</li><li>Desk Organizers & Document Cases</li><li>Canvas Pencil Cases & Tech Sleeves</li></ul> |
| **13** | **Kids, Baby & Toys** | `kids-and-baby` | <ul><li>Baby Apparel (Organic Cotton Onesies, Rompers)</li><li>Developmental & Wooden Toys (Montessori Blocks, Puzzles)</li><li>Feeding & Nursery Gear (Silicone Bowls, Muslin Blankets)</li></ul> |
| **14** | **Pet Supplies** | `pet-supplies` | <ul><li>Dog Gear (Tactical Harnesses, Reflective Leashes, Chew Toys)</li><li>Cat Accessories (Tofu Litter, Scratching Boards, Teasers)</li><li>Pet Health & Grooming (De-Shedding Brushes, Paw Balms)</li></ul> |

---

## 3. Why Subcategories Are Standardized (Never Free-Form Text)

If a platform permits sellers to type free-form strings into category fields, the marketplace collapses:
- Seller A types `tshirt`, Seller B types `T-Shirt`, Seller C types `t shirt`, and Seller D types `Oversized Shirt Murang Mura COD`.
- **The Failure**: Buyer filters duplicate 50 times, search indexes fragment, and recommendation algorithms fail to cluster related items.

### Controlled Vocabulary Governance:
1. **Database-Driven Taxonomy**: Subcategories reside in the `sub_categories` table linked via foreign key to `categories`.
2. **Admin-Managed**: Platform administrators can add, merge, or deprecate subcategories in the Admin Portal without redeploying code.
3. **Seller Single-Category Thesis Invariant**: Sellers remain strictly anchored to their registered Master Category upon account approval. When creating listings, they select from that department's pre-approved Level-2 subcategories.

---

## 4. Multi-Factor Feed Ranking Algorithm Specification

Products are not sorted alphabetically or by creation timestamp. The marketplace feed scores each product using an **Ensemble Ranking Formula**:

$$\text{Product Score} = (S_{\text{affinity}} \times 0.40) + (S_{\text{velocity}} \times 0.25) + (S_{\text{quality}} \times 0.15) + (S_{\text{freshness}} \times 0.10) + (S_{\text{exploration}} \times 0.10)$$

### Formula Breakdown:

```
┌──────────────────────────────────┬───────┬────────────────────────────────────────────────────────┐
│ Factor                           │ Weight│ Metrics & Implementation                               │
├──────────────────────────────────┼───────┼────────────────────────────────────────────────────────┤
│ 1. Buyer Personalization Affinity│  40%  │ Matching buyer's recently viewed subcategories & tags   │
│ 2. Sales Velocity (Popularity)   │  25%  │ Completed orders in last 30 days & click-through-rate  │
│ 3. Shop & Product Quality        │  15%  │ Rating (e.g. 4.9★ vs 3.2★) and low dispute rate (<2%)  │
│ 4. Freshness & Local Proximity   │  10%  │ Items listed within 72 hrs + same-municipality hub bonus│
│ 5. Cold-Start Exploration (RNG)  │  10%  │ Controlled random seed (Multi-Armed Bandit honeymoon)   │
└──────────────────────────────────┴───────┴────────────────────────────────────────────────────────┘
```

### 4.1 Cold-Start Resolution (The "RNG" Fairness Engine)
1. **New Buyer Cold Start**:
   - For an unauthenticated or newly registered buyer with 0 click history, the algorithm serves a **curated discovery mix**: 40% top-rated bestsellers, 30% active flash deals, and 30% randomized category sampling.
   - Upon the buyer's **first 3 product views**, their personal Affinity Vector recalculates, dynamically biasing the feed toward their revealed aesthetic.
2. **New Seller / Listing Cold Start (Traffic Honeymoon)**:
   - To prevent older sellers from monopolizing feed visibility, every new product listing receives a guaranteed **Exploration Quota** (~300 impressions).
   - If user engagement exceeds baseline thresholds during exploration, the listing graduates into the standard algorithmic velocity pool.
