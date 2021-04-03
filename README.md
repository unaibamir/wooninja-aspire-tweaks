# RCPro Strip Payment Gateway & Groups Addon Customization 
This addon override's RCPro Stripe Plans and overrides Group's addon to charge user for adding any user to group. <br>The user is charged on Stripe for addind any new user in the group. <br>The total subscription amount consists of membership level price + (number of users * per member fee)

### Notes
- This addons requires Restrict Content Pro, RCP Groups Addon & Gravity Forms plugin.
- It is a custom addon made for a specific project therefore, use it on your own risk.

### Detail
- This addon redefines RCPro creates Stripe plans to be changed to tiered mode as graduated.
- This addon charges whenever a user adds a child user in the group.
- This method allows Stripe to charge user based on active number of users in the group for the recurring charges.
- It also provides option for coorporate user to add multiple subscribers at the time of registration.

#### RCP Membership Level Edit Page:
- Admin can configure the price for per additional member in the group. 
- Admin can add/modify the child users in any group reflecting the recurring charges of Stripe. 

#### Frontend Changes:
- Whenever a user add a chid user in the group, the parent user would be charges instantly based on the price of the subscription. 
- If a child user is removed from the group, the Stripe subscription is updated to reflect the updated recurring amount of the subscription.
- If the coorprate account is being used, the corporate user is charged for all payments instead of the parent user. 
