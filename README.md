## My thoughts
I can see that the code were well written, it's just that there are still things or aspects that needs to improved. Like reusability, some logics were repetitively written.
Other advantage of code reusability is to have a single source of truth, when there is a need to change for that particular logic then there is only one place to look at.
Another is, some lengthy functions. This may affect code readability, to aid this, better put into function those related codes, like those that performs operation that generates a value. 
In this way, other dev that will work on this will easily understand the logic and what each function does. Other is, some business logic were placed in the repository where its responsibility is
to access and extract data from the database. Lastly, coding guidelines were not properly implemented pertaining to variable naming convention, spacing and others.

## What makes this a terrible code?

1. Some typo in declaring variable names.

2. Some variables are not properly given a name. Variable names should be named to something more meaningful.

3. Variable and function naming convention were not uniform, some used camel case while some uses snake case. 
Should have uniform variable and function naming convention, if it's snake case then other should also be snake case. Same with pascal or camel case.

4. Some logic are being repetitively written, logic that are common can be contained into a function to be reusable.

5. Request validations seems not properly implemented, with this we can use FormRequest to handle validations.

6. Not sure what coding style guide this code follows, if it doesn't have then it should follow one like PSR (https://www.php-fig.org/psr/psr-2/). 
Following items were not followed in regard to PSR2 coding guidelines;
    - Method Arguments
        - Method arguments with default values MUST go at the end of the argument list.
    - Control Structures
        - There MUST be one space after the control structure keyword
        - The keyword elseif SHOULD be used instead of else if so that all control keywords look like single words.

7. Long if...else chains, makes the code less comprehensible, converted to using switch statement.

8. Some codes are lengthy which can be reduced like using ternary operators to improve code readability.

9. Code line length issue, suggested character line length is 80 characters, however should not be limited to 120 characters.

10. Some business logic like sending of notification were written in the repository which I think is not its responsibility. These logics should be moved into notification services or handlers. 
As well as those functions that handles AJAX request.

## What makes this an amazing code?
1. This follows a repository pattern which separate hard dependencies of models from controllers. 
This means a true separation of concerns between models and controllers, where models are objects that represents only a table or object, 
and any other type of data structure not having the responsibility to communicate or extract data from the database.
2. This repository implementation takes care of the data access logic which helps to avoid fat models and controllers.
3. Single source of truth, when there's a need to debug or fix issue regarding data access logic, then there's only a single place to maintain and worked on.
