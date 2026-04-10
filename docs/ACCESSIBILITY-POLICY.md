# Guide on the Side - Accessibility Features & Testing Policy

**Author:** Caleb Jones (Team Lead)  
**Sprint:** 9

## Accessibility Policy
Guide on the Side is fully compliant with WCAG 2.1 AA standards. 
Verification and testing for accessibility compliance was completed using standard accessibility tools such as Axe, alongside manual verification with screen reader and keyboard navigation.

## Accessibility Features 
The Guide on the Side project offers several custom accessibility features for logged in users.
Features include:
- Custom administration color themes (High Contrast and UPEI Library)
- Custom focus indication (Enables selection of color or outline width)
- Custom font selection (Available choices include the UPEI Library Default (consisting of Lusitana, Roboto, and Roboto Condensed), Arial, Verdana, Tahoma, or the Pressbooks default)
- Custom tutorial keyboard shortcuts (Allows for easy forward and backward navigation or focus changes within Guide-on-the-Side tutorials)

## Accessing Accessibility Features
To access the custom accessibility features offered by the Guide on the Side project, a user must be logged into an account (with any level of access). 
After logging in, users can access the user profile by navigating to the top right of the screen from any page within Pressbooks and selecting "Edit Profile". 
Within the user profile, custom color schemes can be selected from the "Administration Color Scheme" section. 
All other custom accessibility settings are located at the bottom of the user profile under the "Accessibility Settings" section. 
After changing the desired settings, users can select "Update Profile", and their custom accessibility preferences will be saved to their user (some changes such as color or fonts may require a forced reload with Ctrl+Shift+R)

## File Structure for Accessibility Features
Within the 'pb-split-guide' plugin, all dedicated accessibility logic including stylesheets and scripts are located under the 'accessibility-dashboard' subfolder.

```
guide-on-the-side/
├── web/app/plugins/pb-split-guide/   # The plugin (all source code)
    ├── pb-split-guide.php            # Entry point, hooks registration
    └── accessibility-dashboard/                   # Accessibility Features
        ├── assets/                                # Scripts
        │   ├── accessibility-deashboard-profile.js # User profile 
        │   └── accessibility-dashboard.js         # Skip links for keyboard navigation
        ├── styles/                                # Stylesheets
        │   ├── admin-colors-colorblind.css        # High Contrast Admin Color Scheme
        │   ├── admin-colors-upei.css              # UPEI Library Admin Color Scheme
        │   └── profile.css                        # Custom profile elements
        ├── class-pbsg-accessibility-dashboard.php # Hooks, primary accessibility features
        └── README.md                              # Dedicated README for accessibility features
```
